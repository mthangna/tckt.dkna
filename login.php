<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}
$error = '';
if (is_post()) {
    csrf_check();
    if (login_throttled()) {
        $error = 'Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.';
    } elseif (attempt_login(post('username'), (string)post('password'))) {
        $to = $_SESSION['after_login'] ?? '';
        unset($_SESSION['after_login']);
        // Chỉ cho phép chuyển hướng trong ứng dụng
        if ($to && str_starts_with($to, app_base() . '/') && !str_contains($to, '//')) {
            header('Location: ' . $to);
            exit;
        }
        redirect('index.php');
    } else {
        $error = 'Sai tên đăng nhập hoặc mật khẩu.';
    }
}
// Chế độ demo: hiện các tài khoản mẫu còn dùng mật khẩu mặc định (đổi mật khẩu thì tự ẩn)
$demoAccounts = [];
if (setting('demo_mode') === '1') {
    $names = array_column(demo_accounts(), 0);
    $st = db()->prepare('SELECT username, full_name, role, password_hash FROM users WHERE active = 1 AND username IN (' . implode(',', array_fill(0, count($names), '?')) . ')');
    $st->execute($names);
    foreach ($st->fetchAll() as $r) {
        if (password_verify(DEMO_PASSWORD, $r['password_hash'])) {
            $demoAccounts[array_search($r['username'], $names, true)] = $r;
        }
    }
    ksort($demoAccounts);
}
render_header('Đăng nhập', ['bare' => true]);
?>
<div class="card login-card shadow">
  <div class="card-body p-4">
    <div class="text-center mb-3">
      <div class="brand-icon mx-auto mb-2" style="background:#e8f1fb"><i class="bi bi-hospital"></i></div>
      <div class="fw-bold">BỆNH VIỆN HNĐK NGHỆ AN</div>
      <div class="text-muted small">Phòng Tài chính Kế toán</div>
    </div>
    <?php if ($error): ?><div class="alert alert-danger small py-2"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on"><?= csrf_field() ?>
      <div class="mb-3"><label class="form-label">Tên đăng nhập</label><input name="username" class="form-control" value="<?= e(post('username')) ?>" required autofocus></div>
      <div class="mb-3"><label class="form-label">Mật khẩu</label><input name="password" type="password" class="form-control" required></div>
      <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Đăng nhập</button>
    </form>
    <?php if ($demoAccounts): ?>
    <div class="alert alert-warning small mt-3 mb-0" id="demo-accounts">
      <div class="fw-semibold mb-1"><i class="bi bi-cone-striped"></i> Bản demo – tài khoản dùng thử (bấm để điền):</div>
      <table class="table table-sm table-borderless small mb-0">
        <thead><tr><th>Tài khoản</th><th>Mật khẩu</th><th>Quyền</th></tr></thead>
        <tbody>
        <?php foreach ($demoAccounts as $a): ?>
          <tr role="button" data-user="<?= e($a['username']) ?>"><td class="fw-semibold"><?= e($a['username']) ?></td><td><code><?= e(DEMO_PASSWORD) ?></code></td><td><?= e(role_label($a['role'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>
    document.querySelectorAll('#demo-accounts tr[data-user]').forEach(tr => tr.addEventListener('click', () => {
      document.querySelector('[name=username]').value = tr.dataset.user;
      document.querySelector('[name=password]').value = <?= json_encode(DEMO_PASSWORD) ?>;
    }));
    </script>
    <?php endif; ?>
  </div>
</div>
<?php render_footer();
