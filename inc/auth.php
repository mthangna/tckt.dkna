<?php
declare(strict_types=1);

/**
 * Phân quyền 3 cấp:
 *  - admin: toàn quyền, quản lý người dùng
 *  - mod:   cấu hình nghiệp vụ (tài khoản QR, bảng giá, cơ sở KCB, giá xăng), hủy phiếu, xác nhận thanh toán thủ công, xem báo cáo toàn viện
 *  - user:  tạo QR, tra cứu giá, lập/in phiếu chi, xem báo cáo của mình
 */
const ROLE_LEVEL = ['user' => 1, 'mod' => 2, 'admin' => 3];

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT id, username, full_name, role, active FROM users WHERE id = ?');
            $st->execute([$_SESSION['uid']]);
            $u = $st->fetch();
            if ($u && (int)$u['active'] === 1) {
                $user = $u;
            } else {
                unset($_SESSION['uid']);
            }
        }
    }
    return $user;
}

function has_role(string $minRole): bool
{
    $u = current_user();
    return $u !== null && ROLE_LEVEL[$u['role']] >= ROLE_LEVEL[$minRole];
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        if (is_ajax()) {
            json_out(['ok' => false, 'error' => 'Bạn chưa đăng nhập hoặc phiên đã hết hạn.'], 401);
        }
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php');
    }
    return $u;
}

function require_role(string $minRole): array
{
    $u = require_login();
    if (!has_role($minRole)) {
        if (is_ajax()) {
            json_out(['ok' => false, 'error' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
        }
        http_response_code(403);
        render_header('Không có quyền');
        echo '<div class="alert alert-danger">Bạn không có quyền truy cập chức năng này.</div>';
        render_footer();
        exit;
    }
    return $u;
}

/** Chặn dò mật khẩu: tối đa 5 lần sai / 15 phút / IP */
function login_throttled(): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'login_fail' AND ip = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $st->execute([client_ip()]);
    return (int)$st->fetchColumn() >= 5;
}

function attempt_login(string $username, string $password): bool
{
    $st = db()->prepare('SELECT id, password_hash, active FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && (int)$u['active'] === 1 && password_verify($password, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        audit('login', $username, (int)$u['id']);
        return true;
    }
    audit('login_fail', $username, null);
    return false;
}

function logout(): void
{
    audit('logout');
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}
