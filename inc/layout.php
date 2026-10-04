<?php
declare(strict_types=1);

function nav_items(): array
{
    return [
        ['section' => 'Nghiệp vụ'],
        ['href' => 'index.php',        'icon' => 'speedometer2',   'label' => 'Tổng quan',             'role' => 'user'],
        ['href' => 'qr.php',           'icon' => 'qr-code',        'label' => 'Thanh toán QR',         'role' => 'user'],
        ['href' => 'prices.php',       'icon' => 'tags',           'label' => 'Tra cứu bảng giá',      'role' => 'user'],
        ['href' => 'voucher.php',      'icon' => 'truck',          'label' => 'Phiếu chi vận chuyển',  'role' => 'user'],
        ['href' => 'reports.php',      'icon' => 'bar-chart-line', 'label' => 'Báo cáo',               'role' => 'user'],
        ['href' => 'reconcile.php',    'icon' => 'arrow-left-right','label' => 'Đối soát ngân hàng',   'role' => 'mod'],
        ['section' => 'Cấu hình', 'role' => 'mod'],
        ['href' => 'settings_bank.php',       'icon' => 'bank',          'label' => 'Tài khoản nhận tiền', 'role' => 'mod'],
        ['href' => 'settings_prices.php',     'icon' => 'file-earmark-spreadsheet', 'label' => 'Danh mục giá (Excel)', 'role' => 'mod'],
        ['href' => 'settings_facilities.php', 'icon' => 'hospital',      'label' => 'Cơ sở KCB',           'role' => 'mod'],
        ['href' => 'settings_fuel.php',       'icon' => 'fuel-pump',     'label' => 'Giá xăng',            'role' => 'mod'],
        ['href' => 'settings_general.php',    'icon' => 'gear',          'label' => 'Cấu hình chung',      'role' => 'admin'],
        ['href' => 'users.php',               'icon' => 'people',        'label' => 'Người dùng',          'role' => 'admin'],
        ['href' => 'audit.php',               'icon' => 'journal-text',  'label' => 'Nhật ký hệ thống',    'role' => 'admin'],
    ];
}

function render_header(string $title, array $opts = []): void
{
    $u = current_user();
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $bare = !empty($opts['bare']);
    ?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="<?= $bare ? 'bare' : '' ?>">
<?php if (!$bare && $u): ?>
<div class="app-shell">
  <aside class="app-sidebar d-print-none" id="sidebar">
    <div class="brand">
      <div class="brand-icon"><i class="bi bi-hospital"></i></div>
      <div>
        <div class="brand-title">BV HNĐK Nghệ An</div>
        <div class="brand-sub">Phòng Tài chính Kế toán</div>
      </div>
    </div>
    <nav class="nav flex-column">
      <?php foreach (nav_items() as $it):
          if (isset($it['role']) && !has_role($it['role'])) continue;
          if (isset($it['section'])): ?>
        <div class="nav-section"><?= e($it['section']) ?></div>
      <?php else: ?>
        <a class="nav-link<?= $current === $it['href'] ? ' active' : '' ?>" href="<?= e(url($it['href'])) ?>">
          <i class="bi bi-<?= e($it['icon']) ?>"></i><span><?= e($it['label']) ?></span>
        </a>
      <?php endif; endforeach; ?>
    </nav>
    <?php if (setting('demo_mode') === '1'): ?>
      <div class="demo-badge"><i class="bi bi-cone-striped"></i> Đang bật chế độ demo</div>
    <?php endif; ?>
  </aside>
  <div class="app-main">
    <header class="app-topbar d-print-none">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="bi bi-list"></i></button>
      <h1 class="page-title"><?= e($title) ?></h1>
      <div class="dropdown">
        <button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">
          <i class="bi bi-person-circle"></i> <?= e($u['full_name']) ?>
          <span class="badge text-bg-<?= $u['role'] === 'admin' ? 'danger' : ($u['role'] === 'mod' ? 'warning' : 'secondary') ?>"><?= e(role_label($u['role'])) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>"><i class="bi bi-key"></i> Đổi mật khẩu</a></li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?>
              <button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i> Đăng xuất</button>
            </form>
          </li>
        </ul>
      </div>
    </header>
    <main class="app-content">
      <?php foreach (take_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show d-print-none">
          <?= e($f['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endforeach; ?>
<?php else: ?>
<main class="bare-content">
<?php endif;
}

function render_footer(array $scripts = []): void
{
    $u = current_user();
    $bare = !$u;
    if (!$bare): ?>
    </main>
  </div>
</div>
<?php else: ?>
</main>
<?php endif; ?>
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts"></div>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(url($s)) ?>?v=<?= @filemtime(APP_ROOT . '/' . $s) ?: 1 ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
