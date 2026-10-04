<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fuel.php';
require_once __DIR__ . '/inc/number_words.php';
$u = require_login();

$facilities = db()->query('SELECT id, name, distance_km, is_home FROM facilities WHERE active = 1 ORDER BY is_home DESC, name')->fetchAll();
$facById = array_column($facilities, null, 'id');
$fuel = fuel_current();
$lpk = (float)setting('liters_per_km', '0.2');
$rounding = max(1, (int)setting('amount_rounding', '1'));

function calc_amount(float $km, int $price, float $lpk, int $rounding): int
{
    $raw = $km * $lpk * $price;
    return (int)(round($raw / $rounding) * $rounding);
}

if (is_post()) {
    csrf_check();
    $act = post('act');
    if ($act === 'create') {
        $err = [];
        $patient = mb_strtoupper(post('patient_name'));
        $resolveFac = function (string $prefix) use ($facById): array {
            $id = (int)post($prefix . '_id');
            if ($id && isset($facById[$id])) {
                return [$id, $facById[$id]['name']];
            }
            return [null, post($prefix . '_other')];
        };
        [$fromId, $from] = $resolveFac('from');
        [$toId, $to] = $resolveFac('to');
        $km = (float)str_replace(',', '.', post('distance_km'));
        $price = has_role('mod') && post('fuel_price') !== '' ? (int)preg_replace('/\D/', '', post('fuel_price')) : (int)($fuel['price'] ?? 0);
        if ($patient === '') $err[] = 'Nhập họ tên người bệnh.';
        if ($from === '' || $to === '') $err[] = 'Chọn hoặc nhập nơi chuyển đi và nơi chuyển đến.';
        if ($fromId && $toId && $fromId === $toId) $err[] = 'Nơi chuyển đi và nơi chuyển đến phải khác nhau.';
        if ($km <= 0 || $km > 5000) $err[] = 'Khoảng cách không hợp lệ.';
        if ($price < 5000) $err[] = 'Chưa có giá xăng hợp lệ. Liên hệ người điều hành để cài đặt giá xăng.';
        $insurance = strtoupper(preg_replace('/\s+/', '', post('insurance_no')));
        if ($insurance !== '' && !preg_match('/^[A-Z0-9]{10,15}$/', $insurance)) $err[] = 'Số thẻ BHYT không hợp lệ.';
        if ($err) {
            $_SESSION['payment_form'] = $_POST;
            flash('danger', implode(' ', $err));
            redirect('payment.php');
        }
        $amount = calc_amount($km, $price, $lpk, $rounding);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $year = (int)date('Y');
            $st = $pdo->prepare('SELECT COALESCE(MAX(voucher_seq), 0) FROM transport_vouchers WHERE voucher_year = ? FOR UPDATE');
            $st->execute([$year]);
            $seq = (int)$st->fetchColumn() + 1;
            $no = sprintf('%04d/%d', $seq, $year);
            $pdo->prepare('INSERT INTO transport_vouchers (voucher_year, voucher_seq, voucher_no, patient_name, treatment_code, insurance_no, patient_address, receiver_name,
                    from_facility_id, from_facility, to_facility_id, to_facility, distance_km, fuel_price, liters_per_km, amount, amount_words, reason, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$year, $seq, $no, $patient, strtoupper(post('treatment_code')) ?: null, $insurance ?: null, post('patient_address') ?: null,
                    mb_strtoupper(post('receiver_name')) ?: null, $fromId, $from, $toId, $to, $km, $price, $lpk, $amount, vn_number_words($amount),
                    post('reason') ?: (string)setting('voucher_reason'), $u['id']]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        audit('voucher_create', "#$id số $no $patient " . money($amount));
        redirect('payment_print.php?id=' . $id . '&print=1');
    }
    if ($act === 'cancel') {
        require_role('mod');
        $id = (int)post('id');
        $reason = post('cancel_reason');
        if ($reason === '') {
            flash('danger', 'Nhập lý do hủy phiếu.');
        } else {
            $st = db()->prepare("UPDATE transport_vouchers SET status = 'cancelled', cancel_reason = ?, cancelled_by = ?, cancelled_at = NOW() WHERE id = ? AND status = 'active'");
            $st->execute([$reason, $u['id'], $id]);
            if ($st->rowCount()) {
                audit('voucher_cancel', "#$id $reason");
                flash('success', 'Đã hủy phiếu chi.');
            }
        }
        redirect('payment.php');
    }
}

$old = $_SESSION['payment_form'] ?? [];
unset($_SESSION['payment_form']);
$homeId = 0;
foreach ($facilities as $f) {
    if ($f['is_home']) { $homeId = (int)$f['id']; break; }
}
$listSql = 'SELECT v.*, us.full_name AS creator FROM transport_vouchers v JOIN users us ON us.id = v.created_by'
    . (has_role('mod') ? '' : ' WHERE v.created_by = ' . (int)$u['id']) . ' ORDER BY v.id DESC LIMIT 30';
$recent = db()->query($listSql)->fetchAll();

render_header('Phiếu chi hỗ trợ vận chuyển');
// Nơi chuyển đến mặc định là bệnh viện (cơ sở "là bệnh viện mình"); nơi chuyển đi là cơ sở khác
$facOptions = function (int $selected, int $exclude = 0) use ($facilities) {
    $h = '<option value="">— Chọn cơ sở —</option>';
    foreach ($facilities as $f) {
        if ($exclude && (int)$f['id'] === $exclude) continue;
        $h .= '<option value="' . (int)$f['id'] . '" data-km="' . e($f['distance_km'] !== null ? (string)(float)$f['distance_km'] : '') . '"' . ($selected === (int)$f['id'] ? ' selected' : '') . '>' . e($f['name']) . '</option>';
    }
    return $h . '<option value="0">Khác (nhập tay)...</option>';
};
?>
<?php if (!$fuel): ?><div class="alert alert-danger">Chưa cài đặt giá xăng nên chưa lập được phiếu. <?= has_role('mod') ? '<a href="' . e(url('settings_fuel.php')) . '">Cài đặt giá xăng</a>' : 'Liên hệ người điều hành.' ?></div><?php endif; ?>
<div class="row g-4">
  <div class="col-xl-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-truck text-primary"></i> Lập phiếu chi</div>
      <div class="card-body">
        <form method="post" id="voucher-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="act" value="create">
          <div class="row g-2">
            <div class="col-md-7"><label class="form-label">Họ tên người bệnh <span class="text-danger">*</span></label><input name="patient_name" class="form-control text-uppercase" required value="<?= e($old['patient_name'] ?? '') ?>"></div>
            <div class="col-md-5"><label class="form-label">Mã điều trị</label><input name="treatment_code" class="form-control text-uppercase" value="<?= e($old['treatment_code'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Số thẻ BHYT</label><input name="insurance_no" class="form-control text-uppercase" maxlength="15" value="<?= e($old['insurance_no'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Người nhận tiền <span class="small text-muted">(nếu khác người bệnh)</span></label><input name="receiver_name" class="form-control text-uppercase" value="<?= e($old['receiver_name'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Địa chỉ</label><input name="patient_address" class="form-control" value="<?= e($old['patient_address'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Nơi chuyển đi <span class="text-danger">*</span></label>
              <select name="from_id" class="form-select fac-select" data-other="from_other" id="from-select"><?= $facOptions((int)($old['from_id'] ?? 0), $homeId) ?></select>
              <input name="from_other" class="form-control mt-1" placeholder="Tên cơ sở chuyển đi" hidden></div>
            <div class="col-md-6"><label class="form-label">Nơi chuyển đến <span class="text-danger">*</span></label>
              <select name="to_id" class="form-select fac-select" data-other="to_other" id="to-select"><?= $facOptions((int)($old['to_id'] ?? $homeId)) ?></select>
              <input name="to_other" class="form-control mt-1" placeholder="Tên nơi chuyển đến" hidden></div>
            <div class="col-md-4"><label class="form-label">Khoảng cách (km) <span class="text-danger">*</span></label><input name="distance_km" id="v-km" type="number" step="0.1" min="0.1" class="form-control" required value="<?= e($old['distance_km'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Giá xăng (đ/lít)</label>
              <input name="fuel_price" id="v-price" class="form-control" data-money value="<?= $fuel ? money($fuel['price']) : '' ?>" <?= has_role('mod') ? '' : 'readonly' ?>>
              <div class="form-text"><?= $fuel ? e($fuel['fuel_name']) . ', từ ' . vn_date($fuel['effective_at']) : '' ?></div></div>
            <div class="col-md-4"><label class="form-label">Định mức</label><input class="form-control" value="<?= e(str_replace('.', ',', (string)$lpk)) ?> lít/km" readonly></div>
            <div class="col-12"><label class="form-label">Lý do chi</label><input name="reason" class="form-control" value="<?= e($old['reason'] ?? setting('voucher_reason')) ?>"></div>
          </div>
          <div class="bg-primary-subtle rounded p-3 mt-3 text-center">
            <div class="small text-muted">Số tiền hỗ trợ = <span id="v-formula">km × <?= e((string)$lpk) ?> lít × giá xăng</span></div>
            <div class="fs-2 fw-bold text-primary" id="v-total">0 đ</div>
            <div class="small fst-italic" id="v-words">Không đồng</div>
          </div>
          <button class="btn btn-primary w-100 mt-3" id="v-submit" <?= $fuel ? '' : 'disabled' ?>><i class="bi bi-printer"></i> Lưu và in phiếu chi</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between"><span><i class="bi bi-clock-history"></i> Phiếu đã lập gần đây</span><a href="<?= e(url('reports.php?tab=voucher')) ?>" class="small">Xem báo cáo đầy đủ</a></div>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Số</th><th>Ngày</th><th>Người bệnh</th><th>Nơi chuyển đi</th><th class="num">Km</th><th class="num">Số tiền</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $v): $cx = $v['status'] === 'cancelled'; ?>
          <tr class="<?= $cx ? 'text-muted text-decoration-line-through' : '' ?>">
            <td class="text-nowrap"><?= e($v['voucher_no']) ?></td><td class="text-nowrap small"><?= vn_date($v['created_at'], true) ?></td>
            <td><?= e($v['patient_name']) ?></td><td class="small"><?= e($v['from_facility']) ?></td>
            <td class="num"><?= km_text($v['distance_km']) ?></td><td class="num fw-semibold"><?= money($v['amount']) ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-light" href="<?= e(url('payment_print.php?id=' . $v['id'])) ?>" title="Xem / in lại"><i class="bi bi-printer"></i></a>
              <?php if (!$cx && has_role('mod')): ?>
              <button class="btn btn-sm btn-light text-danger" data-cancel="<?= (int)$v['id'] ?>" data-no="<?= e($v['voucher_no']) ?>" title="Hủy phiếu"><i class="bi bi-x-octagon"></i></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; if (!$recent): ?><tr><td colspan="7" class="text-center text-muted py-3">Chưa có phiếu nào</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
<form method="post" id="cancel-form" hidden><?= csrf_field() ?><input type="hidden" name="act" value="cancel"><input type="hidden" name="id"><input type="hidden" name="cancel_reason"></form>
<script>
window.VOUCHER = { lpk: <?= json_encode($lpk) ?>, rounding: <?= json_encode($rounding) ?> };
</script>
<?php render_footer(['assets/js/numwords.js', 'assets/js/payment.js']);
