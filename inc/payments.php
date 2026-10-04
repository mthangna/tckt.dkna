<?php
declare(strict_types=1);

/**
 * Ghi nhận giao dịch tiền vào và tự khớp với yêu cầu thanh toán QR.
 * Quy tắc khớp: nội dung chuyển khoản (đã bỏ dấu, bỏ khoảng trắng) có chứa nội dung của yêu cầu
 * VÀ số tiền bằng đúng số tiền yêu cầu. Nếu nhiều yêu cầu cùng khớp → chọn yêu cầu tạo sớm nhất.
 */
function payment_content_for(string $treatmentCode): string
{
    return transfer_text(setting('qr_prefix', 'DKNA') . ' ' . $treatmentCode);
}

/** @return array{id:int, duplicate:bool, matched:?int} */
function record_bank_txn(string $source, string $ref, ?string $accountNo, string $time, float $amount, string $content, $raw = null): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT id, payment_request_id FROM bank_transactions WHERE source = ? AND ref_no = ?');
    $st->execute([$source, $ref]);
    if ($row = $st->fetch()) {
        return ['id' => (int)$row['id'], 'duplicate' => true, 'matched' => $row['payment_request_id'] ? (int)$row['payment_request_id'] : null];
    }
    $pdo->prepare('INSERT INTO bank_transactions (source, ref_no, account_no, txn_time, amount, content, raw_data) VALUES (?,?,?,?,?,?,?)')
        ->execute([$source, $ref, $accountNo, $time, $amount, mb_substr($content, 0, 500), $raw === null ? null : (is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_UNICODE))]);
    $id = (int)$pdo->lastInsertId();
    return ['id' => $id, 'duplicate' => false, 'matched' => match_bank_txn($id)];
}

function match_bank_txn(int $txnId): ?int
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM bank_transactions WHERE id = ?');
    $st->execute([$txnId]);
    $t = $st->fetch();
    if (!$t || $t['payment_request_id']) {
        return $t['payment_request_id'] ?? null;
    }
    $haystack = str_replace(' ', '', transfer_text((string)$t['content']));
    if ($haystack === '') {
        return null;
    }
    $st = $pdo->prepare("SELECT id, transfer_content FROM payment_requests WHERE status = 'pending' AND amount = ? ORDER BY created_at ASC, id ASC");
    $st->execute([$t['amount']]);
    foreach ($st as $pr) {
        $needle = str_replace(' ', '', (string)$pr['transfer_content']);
        if ($needle !== '' && str_contains($haystack, $needle)) {
            mark_paid((int)$pr['id'], $txnId, 'Tự động khớp (' . $t['source'] . ')');
            return (int)$pr['id'];
        }
    }
    return null;
}

function mark_paid(int $requestId, ?int $txnId, string $note): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE payment_requests SET status = 'paid', paid_at = NOW(), bank_txn_id = ?, confirm_note = ? WHERE id = ? AND status = 'pending'");
        $st->execute([$txnId, mb_substr($note, 0, 255), $requestId]);
        $ok = $st->rowCount() === 1;
        if ($ok && $txnId) {
            $pdo->prepare('UPDATE bank_transactions SET payment_request_id = ? WHERE id = ?')->execute([$requestId, $txnId]);
        }
        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Đọc file sao kê tải từ Internet Banking (Excel/CSV). Nhận diện cột theo tiêu đề:
 * ngày giao dịch, số tham chiếu, số tiền ghi có (hoặc phát sinh có), nội dung/diễn giải.
 * Chỉ lấy giao dịch tiền vào (ghi có > 0).
 */
function parse_statement(string $path, string $ext): array
{
    require_once __DIR__ . '/xlsx.php';
    $raw = $ext === 'csv' ? Xlsx::readCsv($path) : Xlsx::readRows($path);
    $want = [
        'time'    => ['ngay giao dich', 'ngay gd', 'thoi gian', 'ngay hach toan', 'ngay', 'date'],
        'ref'     => ['so tham chieu', 'ma giao dich', 'so but toan', 'so ct', 'ref', 'reference'],
        'credit'  => ['so tien ghi co', 'ghi co', 'phat sinh co', 'tien vao', 'credit', 'so tien'],
        'content' => ['noi dung', 'dien giai', 'mo ta', 'description'],
        // Cột tiền ra: nhận diện để không nhầm với cột ghi có
        'debit'   => ['so tien ghi no', 'ghi no', 'phat sinh no', 'tien ra', 'debit'],
    ];
    $header = null;
    $map = [];
    foreach ($raw as $i => $row) {
        if ($i > 40) {
            break;
        }
        $m = [];
        foreach ($row as $col => $cell) {
            $h = vn_unaccent((string)$cell);
            $best = null;
            $bestLen = 0;
            foreach ($want as $f => $keys) {
                foreach ($keys as $kw) {
                    if (strlen($kw) > $bestLen && str_contains($h, $kw)) {
                        $best = $f;
                        $bestLen = strlen($kw);
                    }
                }
            }
            if ($best && !isset($m[$best])) {
                $m[$best] = $col;
            }
        }
        if (isset($m['time'], $m['credit'], $m['content'])) {
            $header = $i;
            $map = $m;
            break;
        }
    }
    if ($header === null) {
        throw new RuntimeException('Không nhận diện được cột trong file sao kê. Cần có các cột: Ngày giao dịch, Số tiền ghi có, Nội dung.');
    }
    $out = [];
    foreach ($raw as $idx => $row) {
        if ($idx <= $header) {
            continue;
        }
        $credit = PriceImportMoney::parse((string)($row[$map['credit']] ?? ''));
        if (!$credit || $credit <= 0) {
            continue;
        }
        $timeRaw = (string)($row[$map['time']] ?? '');
        $time = statement_time($timeRaw);
        if (!$time) {
            continue;
        }
        $content = (string)($row[$map['content']] ?? '');
        $ref = isset($map['ref']) ? trim((string)($row[$map['ref']] ?? '')) : '';
        if ($ref === '') {
            $ref = 'H' . substr(sha1($time . '|' . $credit . '|' . $content), 0, 20);
        }
        $out[] = ['time' => $time, 'amount' => $credit, 'content' => $content, 'ref' => $ref];
    }
    return $out;
}

function statement_time(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if (is_numeric($s) && (float)$s > 20000 && (float)$s < 80000) {
        return gmdate('Y-m-d H:i:s', (int)round(((float)$s - 25569) * 86400));
    }
    if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?#', $s, $m)) {
        if (!checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

final class PriceImportMoney
{
    public static function parse(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $s = trim(preg_replace('/VND|đ/iu', '', $s));
        if (preg_match('/^\d{1,3}([.,\s]\d{3})+$/u', $s)) {
            return (float)preg_replace('/[.,\s]/u', '', $s);
        }
        if (is_numeric($s)) {
            return (float)$s;
        }
        $digits = preg_replace('/[.,\s]/u', '', $s);
        return ctype_digit($digits) ? (float)$digits : null;
    }
}
