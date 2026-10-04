<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once APP_ROOT . '/inc/vietqr.php';
require_once APP_ROOT . '/inc/payments.php';

$u = require_login();
$action = get('action', post('action'));
if (is_post()) {
    csrf_check();
    $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;
} else {
    $in = $_GET;
}

function load_request(int $id, array $u): array
{
    $st = db()->prepare('SELECT pr.*, b.bank_bin, b.bank_name, b.account_no, b.account_name, us.full_name AS creator
        FROM payment_requests pr JOIN bank_accounts b ON b.id = pr.bank_account_id JOIN users us ON us.id = pr.created_by WHERE pr.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || (!has_role('mod') && (int)$r['created_by'] !== (int)$u['id'])) {
        json_out(['ok' => false, 'error' => 'Không tìm thấy yêu cầu thanh toán.'], 404);
    }
    return $r;
}

function present(array $r): array
{
    return [
        'id'             => (int)$r['id'],
        'treatment_code' => $r['treatment_code'],
        'patient_name'   => $r['patient_name'],
        'amount'         => (int)$r['amount'],
        'content'        => $r['transfer_content'],
        'status'         => $r['status'],
        'paid_at'        => $r['paid_at'] ? vn_date($r['paid_at'], true) : null,
        'confirm_note'   => $r['confirm_note'],
        'created_at'     => vn_date($r['created_at'], true),
        'creator'        => $r['creator'],
        'bank'           => ['name' => $r['bank_name'], 'account_no' => $r['account_no'], 'account_name' => $r['account_name']],
        'payload'        => VietQR::payload($r['bank_bin'], $r['account_no'], (int)$r['amount'], $r['transfer_content']),
    ];
}

switch ($action) {
    case 'create':
        if (!is_post()) break;
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['treatment_code'] ?? '')));
        $amount = (int)preg_replace('/\D/', '', (string)($in['amount'] ?? ''));
        $name = mb_substr(trim((string)($in['patient_name'] ?? '')), 0, 150);
        $accId = (int)($in['bank_account_id'] ?? 0);
        if ($code === '' || strlen($code) > 20) {
            json_out(['ok' => false, 'error' => 'Mã điều trị bắt buộc, chỉ gồm chữ và số, tối đa 20 ký tự.'], 422);
        }
        if ($amount < 1000 || $amount > 9999999999) {
            json_out(['ok' => false, 'error' => 'Số tiền không hợp lệ (tối thiểu 1.000 đ).'], 422);
        }
        $st = db()->prepare($accId ? 'SELECT id FROM bank_accounts WHERE id = ? AND active = 1' : 'SELECT id FROM bank_accounts WHERE active = 1 ORDER BY is_default DESC, id LIMIT 1');
        $st->execute($accId ? [$accId] : []);
        $accId = (int)$st->fetchColumn();
        if (!$accId) {
            json_out(['ok' => false, 'error' => 'Chưa cấu hình tài khoản nhận tiền. Liên hệ quản trị/điều hành.'], 422);
        }
        $content = payment_content_for($code);
        db()->prepare('INSERT INTO payment_requests (treatment_code, patient_name, amount, transfer_content, bank_account_id, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$code, $name ?: null, $amount, $content, $accId, $u['id']]);
        $id = (int)db()->lastInsertId();
        audit('qr_create', "#$id $code " . money($amount));
        json_out(['ok' => true, 'request' => present(load_request($id, $u))]);

    case 'status':
        $r = load_request((int)($in['id'] ?? 0), $u);
        json_out(['ok' => true, 'status' => $r['status'], 'paid_at' => $r['paid_at'] ? vn_date($r['paid_at'], true) : null, 'confirm_note' => $r['confirm_note']]);

    case 'get':
        json_out(['ok' => true, 'request' => present(load_request((int)($in['id'] ?? 0), $u))]);

    case 'list':
        $where = ['DATE(pr.created_at) = ?'];
        $args = [parse_date((string)($in['date'] ?? '')) ?? date('Y-m-d')];
        if (!has_role('mod')) {
            $where[] = 'pr.created_by = ?';
            $args[] = $u['id'];
        }
        $st = db()->prepare('SELECT pr.id, pr.treatment_code, pr.patient_name, pr.amount, pr.status, pr.created_at, pr.paid_at, us.full_name AS creator
            FROM payment_requests pr JOIN users us ON us.id = pr.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY pr.id DESC LIMIT 200');
        $st->execute($args);
        $rows = array_map(fn($r) => [
            'id' => (int)$r['id'], 'treatment_code' => $r['treatment_code'], 'patient_name' => $r['patient_name'],
            'amount' => (int)$r['amount'], 'status' => $r['status'], 'creator' => $r['creator'],
            'time' => date('H:i', strtotime($r['created_at'])), 'paid_at' => $r['paid_at'] ? date('H:i', strtotime($r['paid_at'])) : null,
        ], $st->fetchAll());
        json_out(['ok' => true, 'rows' => $rows]);

    case 'cancel':
        if (!is_post()) break;
        $r = load_request((int)($in['id'] ?? 0), $u);
        db()->prepare("UPDATE payment_requests SET status = 'cancelled' WHERE id = ? AND status = 'pending'")->execute([$r['id']]);
        audit('qr_cancel', '#' . $r['id']);
        json_out(['ok' => true]);

    case 'confirm':
        if (!is_post()) break;
        require_role('mod');
        $r = load_request((int)($in['id'] ?? 0), $u);
        $note = trim((string)($in['note'] ?? ''));
        if ($note === '') {
            json_out(['ok' => false, 'error' => 'Nhập lý do / căn cứ xác nhận (vd: đã kiểm tra trên iBank, số tham chiếu...).'], 422);
        }
        if (!mark_paid((int)$r['id'], null, 'Xác nhận thủ công bởi ' . $u['full_name'] . ': ' . $note)) {
            json_out(['ok' => false, 'error' => 'Yêu cầu không còn ở trạng thái chờ thanh toán.'], 409);
        }
        audit('qr_confirm_manual', '#' . $r['id'] . ' ' . $note);
        json_out(['ok' => true]);

    case 'demo_pay':
        // Chỉ dùng khi bật chế độ demo: tạo một giao dịch tiền vào giả và cho đi qua đúng luồng khớp tự động
        if (!is_post()) break;
        if (setting('demo_mode') !== '1') {
            json_out(['ok' => false, 'error' => 'Chế độ demo đang tắt.'], 403);
        }
        $r = load_request((int)($in['id'] ?? 0), $u);
        $content = 'MBVCB.' . random_int(1000000, 9999999) . '.' . $r['transfer_content'] . '.CT tu 0123456789 NGUYEN VAN A toi ' . $r['account_no'];
        $res = record_bank_txn('demo', 'DEMO' . bin2hex(random_bytes(6)), $r['account_no'], date('Y-m-d H:i:s'), (float)$r['amount'], $content, ['demo' => true]);
        json_out(['ok' => true, 'matched' => $res['matched']]);
}
json_out(['ok' => false, 'error' => 'Yêu cầu không hợp lệ.'], 400);
