<?php
declare(strict_types=1);
// Cài đặt lần đầu: tạo bảng và tài khoản quản trị. Sau khi cài xong sẽ tạo file storage/install.lock để khoá.
require __DIR__ . '/inc/bootstrap.php';

$lock = APP_ROOT . '/storage/install.lock';
$error = '';
if (is_file($lock)) {
    exit('Hệ thống đã được cài đặt. Muốn cài lại, hãy xoá file storage/install.lock.');
}

if (is_post()) {
    csrf_check();
    $username = post('username');
    $fullName = post('full_name');
    $pass = (string)post('password');
    $demo = post('demo') === '1';
    if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username) || $fullName === '' || mb_strlen($pass) < 8) {
        $error = 'Tên đăng nhập 3–50 ký tự (chữ, số, _ .), họ tên bắt buộc, mật khẩu tối thiểu 8 ký tự.';
    } else {
        try {
            $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(APP_ROOT . '/install/schema.sql'));
            foreach (preg_split('/;\s*(\n|$)/', $sql) as $stmt) {
                if (trim($stmt) !== '') {
                    db()->exec($stmt);
                }
            }
            $st = db()->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES (?,?,?,'admin')");
            $st->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $fullName]);
            $adminId = (int)db()->lastInsertId();
            $_SESSION['uid'] = $adminId;
            setting_set('demo_mode', $demo ? '1' : '0');
            if ($demo) {
                require APP_ROOT . '/install/demo_data.php';
                install_demo_data($adminId);
            }
            if (!is_dir(dirname($lock))) {
                mkdir(dirname($lock), 0775, true);
            }
            file_put_contents($lock, date('c'));
            session_regenerate_id(true);
            audit('install', $demo ? 'with demo data' : '');
            flash('success', 'Cài đặt thành công.');
            redirect('index.php');
        } catch (Throwable $ex) {
            $error = 'Lỗi cài đặt: ' . $ex->getMessage();
        }
    }
}
render_header('Cài đặt', ['bare' => true]);
?>
<div class="card login-card shadow">
  <div class="card-body p-4">
    <h1 class="h5 mb-1">Cài đặt hệ thống</h1>
    <p class="text-muted small">Tạo các bảng dữ liệu và tài khoản quản trị đầu tiên.</p>
    <?php if ($error): ?><div class="alert alert-danger small"><?= e($error) ?></div><?php endif; ?>
    <form method="post"><?= csrf_field() ?>
      <div class="mb-2"><label class="form-label">Tên đăng nhập quản trị</label><input name="username" class="form-control" value="<?= e(post('username', 'admin')) ?>" required></div>
      <div class="mb-2"><label class="form-label">Họ tên</label><input name="full_name" class="form-control" value="<?= e(post('full_name', 'Quản trị hệ thống')) ?>" required></div>
      <div class="mb-3"><label class="form-label">Mật khẩu (≥ 8 ký tự)</label><input name="password" type="password" class="form-control" required minlength="8"></div>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="demo" value="1" id="demo" checked><label class="form-check-label" for="demo">Nạp dữ liệu mẫu để xem thử (demo)</label></div>
      <button class="btn btn-primary w-100">Cài đặt</button>
    </form>
  </div>
</div>
<?php render_footer();
