<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fuel.php';
$u = require_login();

$mine = has_role('mod') ? '' : ' AND created_by = ' . (int)$u['id'];
$pdo = db();
$qr = $pdo->query("SELECT
        SUM(status='pending') AS pending,
        SUM(status='paid') AS paid,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount END),0) AS paid_amount
    FROM payment_requests WHERE DATE(created_at) = CURDATE()$mine")->fetch();
$vc = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
    FROM transport_vouchers WHERE status='active' AND DATE(created_at) = CURDATE()$mine")->fetch();
$vcMonth = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
    FROM transport_vouchers WHERE status='active' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')$mine")->fetch();
$fuel = fuel_current();
$unmatched = has_role('mod') ? (int)$pdo->query('SELECT COUNT(*) FROM bank_transactions WHERE payment_request_id IS NULL')->fetchColumn() : 0;

render_header('Tổng quan');
?>
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="card stat-card"><div class="card-body">
    <div class="label"><i class="bi bi-hourglass-split"></i> QR đang chờ tiền (hôm nay)</div>
    <div class="value text-warning"><?= (int)$qr['pending'] ?></div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card stat-card"><div class="card-body">
    <div class="label"><i class="bi bi-check2-circle"></i> Đã nhận qua QR (hôm nay)</div>
    <div class="value text-success"><?= money($qr['paid_amount']) ?> đ</div>
    <div class="small text-muted"><?= (int)$qr['paid'] ?> giao dịch</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card stat-card"><div class="card-body">
    <div class="label"><i class="bi bi-truck"></i> Phiếu chi vận chuyển (hôm nay)</div>
    <div class="value"><?= (int)$vc['n'] ?></div>
    <div class="small text-muted"><?= money($vc['total']) ?> đ · Tháng này: <?= (int)$vcMonth['n'] ?> phiếu, <?= money($vcMonth['total']) ?> đ</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card stat-card"><div class="card-body">
    <div class="label"><i class="bi bi-fuel-pump"></i> Giá xăng đang áp dụng</div>
    <div class="value"><?= $fuel ? money($fuel['price']) . ' đ/lít' : '<span class="text-danger fs-6">Chưa cài đặt</span>' ?></div>
    <div class="small text-muted"><?= $fuel ? e($fuel['fuel_name']) . ' · từ ' . vn_date($fuel['effective_at'], true) : '' ?></div></div></div></div>
</div>

<?php if ($unmatched > 0): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i>
  Có <strong><?= $unmatched ?></strong> giao dịch tiền vào chưa khớp được với yêu cầu QR nào.
  <a href="<?= e(url('reconcile.php')) ?>">Xem và xử lý</a>.</div>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ([
      ['qr.php', 'qr-code', 'Thanh toán QR', 'Tạo mã VietQR cho người bệnh chuyển khoản; tự cập nhật khi tiền về.'],
      ['prices.php', 'tags', 'Tra cứu bảng giá', 'Giá dịch vụ KBCB BHYT, KCB theo yêu cầu và dịch vụ khác.'],
      ['payment.php', 'truck', 'Phiếu chi vận chuyển', 'Tính tiền hỗ trợ vận chuyển (0,2 lít xăng/km), lưu và in phiếu chi.'],
      ['reports.php', 'bar-chart-line', 'Báo cáo', 'Thống kê, xuất Excel phiếu chi và các khoản thu qua QR.'],
  ] as [$href, $icon, $title, $desc]): ?>
  <div class="col-md-6 col-xl-3">
    <a class="card h-100 text-decoration-none" href="<?= e(url($href)) ?>"><div class="card-body">
      <div class="fs-3 text-primary"><i class="bi bi-<?= $icon ?>"></i></div>
      <div class="fw-semibold text-dark"><?= e($title) ?></div>
      <div class="small text-muted"><?= e($desc) ?></div>
    </div></a>
  </div>
  <?php endforeach; ?>
</div>
<?php render_footer();
