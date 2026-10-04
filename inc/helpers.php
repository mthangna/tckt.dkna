<?php
declare(strict_types=1);

/** Escape HTML */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Đường dẫn gốc của ứng dụng (hỗ trợ cài trong thư mục con, ví dụ /tckt) */
function app_base(): string
{
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        if (basename($dir) === 'api') {
            $dir = dirname($dir);
        }
        $base = rtrim($dir, '/');
    }
    return $base;
}

function url(string $path = ''): string
{
    return app_base() . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        if (is_ajax()) {
            json_out(['ok' => false, 'error' => 'Phiên làm việc hết hạn, vui lòng tải lại trang.'], 419);
        }
        exit('Phiên làm việc hết hạn, vui lòng quay lại và tải lại trang.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function post(string $key, $default = '')
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function get(string $key, $default = '')
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function audit(string $action, string $detail = '', ?int $userId = null): void
{
    try {
        $st = db()->prepare('INSERT INTO audit_log (user_id, action, detail, ip) VALUES (?,?,?,?)');
        $st->execute([$userId ?? (current_user()['id'] ?? null), $action, mb_substr($detail, 0, 1000), client_ip()]);
    } catch (Throwable $e) {
        // Không chặn nghiệp vụ nếu ghi log lỗi
    }
}

/* ---------- Cấu hình hệ thống (bảng settings) ---------- */

function settings_defaults(): array
{
    return [
        'org_parent'        => 'SỞ Y TẾ NGHỆ AN',
        'org_name'          => 'BỆNH VIỆN HỮU NGHỊ ĐA KHOA NGHỆ AN',
        'org_dept'          => 'Phòng Tài chính Kế toán',
        'org_place'         => 'Nghệ An',
        'voucher_form_no'   => 'Mẫu số C41-BB',
        'voucher_book_no'   => '',
        'voucher_debit'     => '',
        'voucher_credit'    => '',
        'voucher_reason'    => 'Hỗ trợ chi phí vận chuyển người bệnh chuyển tuyến',
        'liters_per_km'     => '0.2',
        'amount_rounding'   => '1',
        'fuel_name'         => 'Xăng E5 RON 92-II',
        'fuel_source_url'   => 'https://www.petrolimex.com.vn/index.html',
        'fuel_keyword'      => 'E5 RON 92',
        'fuel_zone'         => '1',
        'webhook_api_key'   => '',
        'demo_mode'         => '1',
        'sign_director'     => '',
        'sign_chief_acc'    => '',
        'sign_cashier'      => '',
    ];
}

function setting(string $name, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT name, value FROM settings') as $r) {
                $cache[$r['name']] = $r['value'];
            }
        } catch (Throwable $e) {
        }
    }
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    return $default ?? (settings_defaults()[$name] ?? null);
}

function setting_set(string $name, string $value): void
{
    $st = db()->prepare('INSERT INTO settings (name, value, updated_by) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)');
    $st->execute([$name, $value, current_user()['id'] ?? null]);
}

/* ---------- Định dạng ---------- */

function money($n): string
{
    return number_format((float)$n, 0, ',', '.');
}

function vn_date(?string $date, bool $withTime = false): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts) : '';
}

/** Chuyển dd/mm/yyyy hoặc yyyy-mm-dd thành yyyy-mm-dd; trả null nếu không hợp lệ */
function parse_date(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $s, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** Bỏ dấu tiếng Việt, chữ thường – dùng cho tìm kiếm */
function vn_unaccent(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $map = [
        'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ',
        'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ',
    ];
    foreach ($map as $to => $chars) {
        $s = str_replace(mb_str_split($chars), $to, $s);
    }
    // Dấu tổ hợp (Unicode dựng sẵn khác chuẩn)
    $s = preg_replace('/\p{Mn}+/u', '', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

/** Chuẩn hoá nội dung chuyển khoản: chữ in hoa không dấu, chỉ A-Z 0-9 và khoảng trắng */
function transfer_text(string $s): string
{
    $s = strtoupper(vn_unaccent($s));
    $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

/** Tài khoản mẫu tạo khi cài kèm dữ liệu demo (mật khẩu chung DEMO_PASSWORD) */
const DEMO_PASSWORD = 'demo1234';
function demo_accounts(): array
{
    return [
        ['quantri', 'Lê Văn Quản Trị', 'admin'],
        ['dieuhanh', 'Nguyễn Thị Điều Hành', 'mod'],
        ['ketoan', 'Trần Văn Kế Toán', 'user'],
    ];
}

function role_label(string $role): string
{
    return ['admin' => 'Quản trị', 'mod' => 'Điều hành', 'user' => 'Người dùng'][$role] ?? $role;
}

function category_label(string $c): string
{
    return [
        'bhyt'   => 'Giá dịch vụ KBCB BHYT',
        'yeucau' => 'Giá dịch vụ KCB theo yêu cầu',
        'khac'   => 'Giá dịch vụ khác',
    ][$c] ?? $c;
}

/** Dịch vụ khác không có mã kỹ thuật/mã tương đương: dùng "Mã dịch vụ" (lưu ở cột equiv_code) */
function price_code_label(string $c): string
{
    return $c === 'khac' ? 'Mã dịch vụ' : 'Mã kỹ thuật';
}

function price_categories(): array
{
    return ['bhyt', 'yeucau', 'khac'];
}

/** Hiển thị số km: bỏ số 0 thừa, dấu phẩy thập phân (295 / 52,5) */
function km_text($n): string
{
    $s = rtrim(rtrim(number_format((float)$n, 1, ',', '.'), '0'), ',');
    return $s === '' ? '0' : $s;
}
