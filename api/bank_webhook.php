<?php
declare(strict_types=1);
/**
 * Điểm nhận thông báo giao dịch tiền vào (webhook).
 * Hiện hỗ trợ định dạng của SePay (trung gian kết nối BIDV) và một định dạng chung đơn giản.
 * Khi ký kết nối trực tiếp với BIDV iConnect, chỉ cần viết thêm hàm chuyển đổi trong normalize_webhook().
 *
 * Xác thực: header "Authorization: Apikey <khoá>" hoặc "X-Api-Key: <khoá>", khoá cấu hình tại Cấu hình chung.
 * Endpoint này KHÔNG dùng phiên đăng nhập; chỉ endpoint này nên được mở ra Internet.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once APP_ROOT . '/inc/payments.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

$key = (string)setting('webhook_api_key', '');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$given = '';
if (preg_match('/^Apikey\s+(.+)$/i', $auth, $m)) {
    $given = trim($m[1]);
} elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $given = trim($_SERVER['HTTP_X_API_KEY']);
}
if ($key === '' || strlen($key) < 16 || !hash_equals($key, $given)) {
    audit('webhook_denied', client_ip());
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$body = file_get_contents('php://input') ?: '';
$data = json_decode($body, true);
if (!is_array($data)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Invalid JSON']));
}

function normalize_webhook(array $d): ?array
{
    // SePay: https://docs.sepay.vn/tich-hop-webhooks.html
    if (isset($d['transferAmount'], $d['transferType'])) {
        if ($d['transferType'] !== 'in') {
            return null; // bỏ qua tiền ra
        }
        return [
            'ref'     => 'sepay-' . ($d['id'] ?? ($d['referenceCode'] ?? sha1(json_encode($d)))),
            'account' => $d['subAccount'] ?? ($d['accountNumber'] ?? null),
            'time'    => $d['transactionDate'] ?? date('Y-m-d H:i:s'),
            'amount'  => (float)$d['transferAmount'],
            'content' => (string)($d['content'] ?? ($d['description'] ?? '')),
        ];
    }
    // Định dạng chung: {ref, account, time, amount, content}
    if (isset($d['ref'], $d['amount'], $d['content'])) {
        return [
            'ref'     => (string)$d['ref'],
            'account' => $d['account'] ?? null,
            'time'    => $d['time'] ?? date('Y-m-d H:i:s'),
            'amount'  => (float)$d['amount'],
            'content' => (string)$d['content'],
        ];
    }
    return null;
}

$t = normalize_webhook($data);
if ($t === null) {
    // Trả 200 để bên gửi không gửi lại các sự kiện không liên quan (vd tiền ra)
    exit(json_encode(['success' => true, 'message' => 'ignored']));
}
$time = date('Y-m-d H:i:s', strtotime((string)$t['time']) ?: time());
try {
    $res = record_bank_txn('webhook', $t['ref'], $t['account'], $time, $t['amount'], $t['content'], $body);
    audit('webhook_txn', $t['ref'] . ' ' . money($t['amount']) . ($res['matched'] ? ' → #' . $res['matched'] : ' (chưa khớp)'));
    echo json_encode(['success' => true, 'matched' => $res['matched'], 'duplicate' => $res['duplicate']]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
