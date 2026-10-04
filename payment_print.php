<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

$st = db()->prepare('SELECT v.*, us.full_name AS creator FROM transport_vouchers v JOIN users us ON us.id = v.created_by WHERE v.id = ?');
$st->execute([(int)get('id')]);
$v = $st->fetch();
if (!$v || (!has_role('mod') && (int)$v['created_by'] !== (int)$u['id'])) {
    http_response_code(404);
    exit('Không tìm thấy phiếu chi.');
}
if (is_post()) {
    csrf_check();
    db()->prepare('UPDATE transport_vouchers SET print_count = print_count + 1 WHERE id = ?')->execute([$v['id']]);
    audit('voucher_print', '#' . $v['id']);
    json_out(['ok' => true]);
}
$ts = strtotime($v['created_at']);
$km = rtrim(rtrim((string)$v['distance_km'], '0'), '.');
$lpk = rtrim(rtrim((string)$v['liters_per_km'], '0'), '.');
$receiver = $v['receiver_name'] ?: $v['patient_name'];
$detail = sprintf('Người bệnh %s%s%s. Chuyển từ %s đến %s, quãng đường %s km × %s lít/km × %s đ/lít.',
    $v['patient_name'],
    $v['treatment_code'] ? ', mã điều trị ' . $v['treatment_code'] : '',
    $v['insurance_no'] ? ', thẻ BHYT ' . $v['insurance_no'] : '',
    $v['from_facility'], $v['to_facility'], km_text($v['distance_km']), str_replace('.', ',', $lpk), money($v['fuel_price']));
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title>Phiếu chi <?= e($v['voucher_no']) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<style>
  @page { size: A5 landscape; margin: 8mm 10mm; }
  body { font-family: 'Times New Roman', Times, serif; font-size: 13.5pt; color: #000; background: #e9edf2; margin: 0; }
  .toolbar { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #ccd; padding: 8px 16px; display: flex; gap: 8px; align-items: center; font-family: system-ui, sans-serif; font-size: 14px; }
  .toolbar button, .toolbar a { padding: 6px 12px; border: 1px solid #b8c2cf; border-radius: 6px; background: #fff; color: #123; text-decoration: none; cursor: pointer; font-size: 14px; }
  .toolbar .primary { background: #0b4a8b; color: #fff; border-color: #0b4a8b; }
  .sheet { width: 190mm; min-height: 128mm; margin: 16px auto; background: #fff; padding: 8mm 10mm; box-shadow: 0 2px 8px rgba(0,0,0,.15); position: relative; box-sizing: border-box; }
  .head { display: flex; justify-content: space-between; font-size: 11.5pt; }
  .head .left { text-align: center; } .head .right { text-align: center; }
  .title { text-align: center; margin: 4mm 0 0; font-size: 17pt; font-weight: bold; }
  .meta { display: flex; justify-content: space-between; align-items: flex-start; }
  .meta .date { text-align: center; flex: 1; }
  .meta .date .d { font-style: italic; }
  .meta .acc { font-size: 11.5pt; min-width: 38mm; }
  .line { margin: 1.5mm 0; line-height: 1.35; }
  .sign { display: flex; justify-content: space-between; text-align: center; margin-top: 4mm; font-size: 11.5pt; }
  .sign div { flex: 1; } .sign .name { margin-top: 16mm; font-weight: bold; }
  .cancelled::after { content: 'ĐÃ HỦY'; position: absolute; top: 40%; left: 25%; font-size: 60pt; color: rgba(200,0,0,.25); transform: rotate(-20deg); border: 6px solid rgba(200,0,0,.25); padding: 0 20px; }
  @media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; box-shadow: none; padding: 0; width: auto; min-height: 0; } }
</style>
</head>
<body>
<div class="toolbar">
  <a href="<?= e(url('payment.php')) ?>"><i class="bi bi-arrow-left"></i> Quay lại</a>
  <button class="primary" id="btn-print"><i class="bi bi-printer"></i> In phiếu</button>
  <span style="color:#667">Số <?= e($v['voucher_no']) ?> · đã in <?= (int)$v['print_count'] ?> lần<?= $v['status'] === 'cancelled' ? ' · <strong style="color:#c00">ĐÃ HỦY: ' . e($v['cancel_reason']) . '</strong>' : '' ?></span>
</div>
<div class="sheet<?= $v['status'] === 'cancelled' ? ' cancelled' : '' ?>">
  <div class="head">
    <div class="left"><div><?= e(setting('org_parent')) ?></div><div><b><?= e(setting('org_name')) ?></b></div><div><?= e(setting('org_dept')) ?></div></div>
    <div class="right"><b><?= e(setting('voucher_form_no')) ?></b></div>
  </div>
  <div class="meta">
    <div style="min-width:38mm"></div>
    <div class="date">
      <div class="title">PHIẾU CHI</div>
      <div class="d">Ngày <?= date("d", $ts) ?> tháng <?= date("m", $ts) ?> năm <?= date("Y", $ts) ?></div>
    </div>
    <div class="acc">
      <div>Quyển số: <?= e(setting('voucher_book_no')) ?></div>
      <div>Số: <b><?= e($v['voucher_no']) ?></b></div>
      <div>Nợ: <?= e(setting('voucher_debit')) ?></div>
      <div>Có: <?= e(setting('voucher_credit')) ?></div>
    </div>
  </div>
  <div class="line">Họ và tên người nhận tiền: <b><?= e($receiver) ?></b></div>
  <div class="line">Địa chỉ: <?= e($v['patient_address'] ?: '') ?></div>
  <div class="line">Lý do chi: <?= e($v['reason']) ?>. <?= e($detail) ?></div>
  <div class="line">Số tiền: <b><?= money($v['amount']) ?> đồng</b></div>
  <div class="line">Viết bằng chữ: <i><?= e($v['amount_words']) ?>.</i></div>
  <div class="line">Kèm theo: ........ chứng từ gốc.</div>
  <div class="sign">
    <div><b>Thủ trưởng đơn vị</b><br><i>(Ký, họ tên, đóng dấu)</i><div class="name"><?= e(setting('sign_director')) ?></div></div>
    <div><b>Kế toán trưởng</b><br><i>(Ký, họ tên)</i><div class="name"><?= e(setting('sign_chief_acc')) ?></div></div>
    <div><b>Người lập phiếu</b><br><i>(Ký, họ tên)</i><div class="name"><?= e($v['creator']) ?></div></div>
    <div><b>Thủ quỹ</b><br><i>(Ký, họ tên)</i><div class="name"><?= e(setting('sign_cashier')) ?></div></div>
    <div><b>Người nhận tiền</b><br><i>(Ký, họ tên)</i><div class="name"><?= e($receiver) ?></div></div>
  </div>
  <div class="line" style="margin-top:3mm;font-size:11.5pt">Đã nhận đủ số tiền (viết bằng chữ): ...........................................................................................................</div>
</div>
<script>
(() => {
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const doPrint = () => {
    fetch(location.href, { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).catch(() => {});
    window.print();
  };
  document.getElementById('btn-print').addEventListener('click', doPrint);
  <?php if (get('print') === '1' && $v['status'] === 'active'): ?>
  window.addEventListener('load', () => setTimeout(doPrint, 300));
  <?php endif; ?>
})();
</script>
</body>
</html>
