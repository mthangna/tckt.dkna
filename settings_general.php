<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');

$fields = [
    'Đơn vị (in trên phiếu chi)' => [
        'org_parent' => 'Cơ quan chủ quản', 'org_name' => 'Tên đơn vị', 'org_dept' => 'Bộ phận', 'org_place' => 'Địa danh ghi ngày tháng',
    ],
    'Phiếu chi' => [
        'voucher_form_no' => 'Mẫu số (theo chế độ kế toán đang áp dụng)', 'voucher_book_no' => 'Quyển số', 'voucher_debit' => 'Tài khoản Nợ', 'voucher_credit' => 'Tài khoản Có',
        'voucher_reason' => 'Lý do chi mặc định', 'sign_director' => 'Họ tên Thủ trưởng đơn vị', 'sign_chief_acc' => 'Họ tên Kế toán trưởng', 'sign_cashier' => 'Họ tên Thủ quỹ',
    ],
    'Tính tiền hỗ trợ vận chuyển' => [
        'liters_per_km' => 'Định mức (lít xăng / km)', 'amount_rounding' => 'Làm tròn số tiền đến (1 = đồng, 100, 1000)',
    ],
];

if (is_post()) {
    csrf_check();
    if (post('act') === 'gen_key') {
        $newKey = bin2hex(random_bytes(24));
        setting_set('webhook_api_key', $newKey);
        $_SESSION['show_key'] = $newKey;
        audit('webhook_key_regen');
        flash('success', 'Đã tạo khoá API webhook mới. Cần cập nhật khoá này ở phía ngân hàng/đối tác.');
    } else {
        foreach ($fields as $group) {
            foreach ($group as $k => $label) {
                $v = (string)post($k);
                if ($k === 'liters_per_km' && (!is_numeric($v) || (float)$v <= 0 || (float)$v > 1)) {
                    flash('danger', 'Định mức lít/km không hợp lệ.');
                    redirect('settings_general.php');
                }
                if ($k === 'amount_rounding' && !in_array($v, ['1', '10', '100', '1000'], true)) {
                    $v = '1';
                }
                setting_set($k, $v);
            }
        }
        setting_set('demo_mode', post('demo_mode') === '1' ? '1' : '0');
        audit('settings_update');
        flash('success', 'Đã lưu cấu hình.');
    }
    redirect('settings_general.php');
}
render_header('Cấu hình chung');
?>
<form method="post"><?= csrf_field() ?>
<div class="row g-4">
  <?php foreach ($fields as $group => $items): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><?= e($group) ?></div><div class="card-body">
    <?php foreach ($items as $k => $label): ?>
      <div class="mb-2"><label class="form-label small mb-1"><?= e($label) ?></label>
        <?php if ($k === 'amount_rounding'): ?>
          <select name="<?= $k ?>" class="form-select form-select-sm"><?php foreach (['1' => 'Đến đồng', '100' => 'Đến 100 đồng', '1000' => 'Đến 1.000 đồng'] as $v => $l): ?><option value="<?= $v ?>" <?= setting($k) === (string)$v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <?php else: ?>
          <input name="<?= $k ?>" class="form-control form-control-sm" value="<?= e(setting($k)) ?>">
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div></div></div>
  <?php endforeach; ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Chế độ demo</div><div class="card-body">
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="demo_mode" value="1" id="demo" <?= setting('demo_mode') === '1' ? 'checked' : '' ?>>
      <label class="form-check-label" for="demo">Bật nút "Giả lập tiền về" ở trang QR để xem thử luồng xác nhận</label></div>
    <div class="form-text text-danger">Tắt chế độ này khi đưa vào sử dụng thật.</div>
  </div></div></div>
</div>
<button class="btn btn-primary mt-4"><i class="bi bi-save"></i> Lưu cấu hình</button>
</form>

<div class="card mt-4"><div class="card-header"><i class="bi bi-plug"></i> Kết nối nhận thông báo giao dịch (webhook)</div><div class="card-body">
  <p class="small mb-2">Địa chỉ nhận: <code><?= e((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'may-chu') . url('api/bank_webhook.php')) ?></code></p>
  <p class="small mb-2">Xác thực bằng header <code>Authorization: Apikey &lt;khoá&gt;</code>. Hỗ trợ sẵn định dạng của SePay; khi có tài liệu BIDV iConnect sẽ bổ sung bộ chuyển đổi.</p>
  <p class="small mb-2">Khoá hiện tại: <?php $k = (string)setting('webhook_api_key'); echo $k ? '<code>' . e(substr($k, 0, 6)) . '…' . e(substr($k, -4)) . '</code>' : '<span class="text-danger">chưa có (webhook đang tắt)</span>'; ?></p>
  <form method="post" data-confirm="Tạo khoá mới sẽ làm khoá cũ mất hiệu lực. Tiếp tục?"><?= csrf_field() ?><input type="hidden" name="act" value="gen_key">
    <button class="btn btn-sm btn-outline-danger">Tạo khoá mới</button></form>
  <?php if (!empty($_SESSION['show_key'])): ?>
    <div class="alert alert-warning small mt-3 mb-0">Khoá mới (chỉ hiển thị một lần, hãy sao chép ngay): <code class="user-select-all"><?= e($_SESSION['show_key']) ?></code></div>
  <?php unset($_SESSION['show_key']); endif; ?>
</div></div>
<?php render_footer();
