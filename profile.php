<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

if (is_post()) {
    csrf_check();
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    $hash = (string)$st->fetchColumn();
    $new = (string)post('new_password');
    if (!password_verify((string)post('old_password'), $hash)) {
        flash('danger', 'Mật khẩu hiện tại không đúng.');
    } elseif (mb_strlen($new) < 8 || $new !== post('new_password2')) {
        flash('danger', 'Mật khẩu mới phải có ít nhất 8 ký tự và nhập lại phải trùng khớp.');
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        audit('change_password');
        flash('success', 'Đã đổi mật khẩu.');
    }
    redirect('profile.php');
}
render_header('Đổi mật khẩu');
?>
<div class="card" style="max-width:480px">
  <div class="card-body">
    <form method="post"><?= csrf_field() ?>
      <div class="mb-3"><label class="form-label">Mật khẩu hiện tại</label><input type="password" name="old_password" class="form-control" required></div>
      <div class="mb-3"><label class="form-label">Mật khẩu mới</label><input type="password" name="new_password" class="form-control" minlength="8" required></div>
      <div class="mb-3"><label class="form-label">Nhập lại mật khẩu mới</label><input type="password" name="new_password2" class="form-control" minlength="8" required></div>
      <button class="btn btn-primary">Lưu</button>
    </form>
  </div>
</div>
<?php render_footer();
