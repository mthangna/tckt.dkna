<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$me = require_role('admin');

if (is_post()) {
    csrf_check();
    $act = post('act');
    $id = (int)post('id');
    $role = post('role');
    if ($act === 'save') {
        $username = post('username');
        $fullName = post('full_name');
        $pass = (string)post('password');
        if (!in_array($role, ['admin', 'mod', 'user'], true) || $fullName === '') {
            flash('danger', 'Họ tên và vai trò bắt buộc.');
        } elseif ($id) {
            if ($id === (int)$me['id'] && $role !== 'admin') {
                flash('danger', 'Không thể tự hạ quyền của chính mình.');
            } else {
                db()->prepare('UPDATE users SET full_name = ?, role = ? WHERE id = ?')->execute([$fullName, $role, $id]);
                if ($pass !== '') {
                    if (mb_strlen($pass) < 8) {
                        flash('danger', 'Mật khẩu tối thiểu 8 ký tự.');
                        redirect('users.php?edit=' . $id);
                    }
                    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                }
                audit('user_update', "#$id $role" . ($pass !== '' ? ' (đặt lại mật khẩu)' : ''));
                flash('success', 'Đã lưu người dùng.');
            }
        } else {
            if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username) || mb_strlen($pass) < 8) {
                flash('danger', 'Tên đăng nhập 3–50 ký tự (chữ, số, _ .) và mật khẩu tối thiểu 8 ký tự.');
            } else {
                try {
                    db()->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?,?,?,?)')
                        ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $fullName, $role]);
                    audit('user_create', "$username $role");
                    flash('success', 'Đã tạo người dùng ' . $username . '.');
                } catch (PDOException $e) {
                    flash('danger', 'Tên đăng nhập đã tồn tại.');
                }
            }
        }
    } elseif ($act === 'toggle' && $id && $id !== (int)$me['id']) {
        db()->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$id]);
        audit('user_toggle', "#$id");
    }
    redirect('users.php');
}
$rows = db()->query('SELECT * FROM users ORDER BY active DESC, FIELD(role, "admin","mod","user"), username')->fetchAll();
$edit = null;
foreach ($rows as $r) {
    if ((int)get('edit') === (int)$r['id']) $edit = $r;
}
render_header('Quản lý người dùng');
?>
<div class="row g-4">
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Tên đăng nhập</th><th>Họ tên</th><th>Vai trò</th><th>Đăng nhập gần nhất</th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr class="<?= $r['active'] ? '' : 'text-muted' ?>">
          <td class="fw-semibold"><?= e($r['username']) ?><?= $r['active'] ? '' : ' <span class="badge text-bg-secondary">Khoá</span>' ?></td>
          <td><?= e($r['full_name']) ?></td>
          <td><span class="badge text-bg-<?= $r['role'] === 'admin' ? 'danger' : ($r['role'] === 'mod' ? 'warning' : 'secondary') ?>"><?= e(role_label($r['role'])) ?></span></td>
          <td class="small"><?= vn_date($r['last_login_at'], true) ?></td>
          <td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="?edit=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a>
          <?php if ((int)$r['id'] !== (int)$me['id']): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light" title="Khoá/mở khoá"><i class="bi bi-lock"></i></button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div></div>
    <div class="card mt-4"><div class="card-body small">
      <strong>Phân quyền:</strong>
      <ul class="mb-0">
        <li><strong>Quản trị (admin):</strong> toàn quyền; quản lý người dùng, cấu hình chung, khoá API webhook, xem nhật ký.</li>
        <li><strong>Điều hành (mod):</strong> cấu hình tài khoản nhận tiền, danh mục giá, cơ sở KCB, giá xăng; đối soát và xác nhận thanh toán thủ công; hủy phiếu chi; xem báo cáo toàn bộ.</li>
        <li><strong>Người dùng (user):</strong> tạo QR thanh toán, tra cứu giá, lập và in phiếu chi; xem báo cáo do mình lập.</li>
      </ul>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><?= $edit ? 'Sửa người dùng' : 'Thêm người dùng' ?></div><div class="card-body">
      <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="mb-2"><label class="form-label">Tên đăng nhập</label><input name="username" class="form-control" value="<?= e($edit['username'] ?? '') ?>" <?= $edit ? 'disabled' : 'required' ?>></div>
        <div class="mb-2"><label class="form-label">Họ tên</label><input name="full_name" class="form-control" value="<?= e($edit['full_name'] ?? '') ?>" required></div>
        <div class="mb-2"><label class="form-label">Vai trò</label><select name="role" class="form-select">
          <?php foreach (['user', 'mod', 'admin'] as $r): ?><option value="<?= $r ?>" <?= ($edit['role'] ?? 'user') === $r ? 'selected' : '' ?>><?= role_label($r) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label"><?= $edit ? 'Đặt lại mật khẩu (để trống nếu không đổi)' : 'Mật khẩu' ?></label><input name="password" type="password" class="form-control" minlength="8" <?= $edit ? '' : 'required' ?> autocomplete="new-password"></div>
        <button class="btn btn-primary">Lưu</button> <?php if ($edit): ?><a class="btn btn-light" href="users.php">Hủy</a><?php endif; ?>
      </form>
    </div></div>
  </div>
</div>
<?php render_footer();
