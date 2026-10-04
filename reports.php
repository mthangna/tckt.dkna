<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/xlsx.php';
$u = require_login();

$tab = in_array(get('tab'), ['voucher', 'qr', 'summary'], true) ? get('tab') : 'voucher';
$from = parse_date(get('from')) ?? date('Y-m-01');
$to = parse_date(get('to')) ?? date('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$isMod = has_role('mod');
$userFilter = $isMod ? (int)get('user') : (int)$u['id'];
$users = $isMod ? db()->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll() : [];
$range = 'Từ ' . vn_date($from) . ' đến ' . vn_date($to);
$export = get('export') === '1';

function where_common(string $alias, string $from, string $to, int $userFilter): array
{
    $w = ["$alias.created_at >= ?", "$alias.created_at < DATE_ADD(?, INTERVAL 1 DAY)"];
    $a = [$from, $to];
    if ($userFilter) {
        $w[] = "$alias.created_by = ?";
        $a[] = $userFilter;
    }
    return [$w, $a];
}

/* ---------- Phiếu chi vận chuyển ---------- */
if ($tab === 'voucher') {
    [$w, $a] = where_common('v', $from, $to, $userFilter);
    $status = get('status', 'active');
    if ($status !== 'all') {
        $w[] = 'v.status = ?';
        $a[] = $status === 'cancelled' ? 'cancelled' : 'active';
    }
    if (($fromFac = get('from_fac')) !== '') {
        $w[] = 'v.from_facility LIKE ?';
        $a[] = '%' . $fromFac . '%';
    }
    $where = implode(' AND ', $w);
    $st = db()->prepare("SELECT v.*, us.full_name AS creator FROM transport_vouchers v JOIN users us ON us.id = v.created_by WHERE $where ORDER BY v.created_at, v.id");
    $st->execute($a);
    $rows = $st->fetchAll();
    if ($export) {
        $out = [];
        foreach ($rows as $i => $r) {
            $out[] = [$i + 1, $r['voucher_no'], vn_date($r['created_at'], true), $r['patient_name'], (string)$r['treatment_code'], (string)$r['insurance_no'],
                $r['from_facility'], $r['to_facility'], (float)$r['distance_km'], (int)$r['fuel_price'], (int)$r['amount'], $r['creator'],
                $r['status'] === 'cancelled' ? 'Đã hủy: ' . $r['cancel_reason'] : ''];
        }
        $out[] = [['Tổng cộng'], '', '', '', '', '', '', '', (float)array_sum(array_column(array_filter($rows, fn($r) => $r['status'] === 'active'), 'distance_km')), '',
            (int)array_sum(array_column(array_filter($rows, fn($r) => $r['status'] === 'active'), 'amount')), '', ''];
        audit('report_export', "voucher $from..$to");
        Xlsx::download("bao_cao_phieu_chi_van_chuyen_{$from}_{$to}.xlsx",
            ['STT', 'Số phiếu', 'Ngày lập', 'Người bệnh', 'Mã điều trị', 'Số thẻ BHYT', 'Nơi chuyển đi', 'Nơi chuyển đến', 'Km', 'Giá xăng', 'Số tiền', 'Người lập', 'Ghi chú'],
            $out, 'BÁO CÁO CHI HỖ TRỢ VẬN CHUYỂN NGƯỜI BỆNH – ' . mb_strtoupper($range));
    }
    $active = array_filter($rows, fn($r) => $r['status'] === 'active');
    $byFac = [];
    foreach ($active as $r) {
        $k = $r['from_facility'];
        $byFac[$k]['n'] = ($byFac[$k]['n'] ?? 0) + 1;
        $byFac[$k]['amount'] = ($byFac[$k]['amount'] ?? 0) + (float)$r['amount'];
    }
    uasort($byFac, fn($x, $y) => $y['amount'] <=> $x['amount']);
}

/* ---------- Thanh toán QR ---------- */
if ($tab === 'qr') {
    [$w, $a] = where_common('pr', $from, $to, $userFilter);
    $status = get('status', 'all');
    if (in_array($status, ['pending', 'paid', 'cancelled'], true)) {
        $w[] = 'pr.status = ?';
        $a[] = $status;
    }
    $where = implode(' AND ', $w);
    $st = db()->prepare("SELECT pr.*, us.full_name AS creator, b.account_no, bt.ref_no, bt.source FROM payment_requests pr
        JOIN users us ON us.id = pr.created_by JOIN bank_accounts b ON b.id = pr.bank_account_id
        LEFT JOIN bank_transactions bt ON bt.id = pr.bank_txn_id WHERE $where ORDER BY pr.created_at, pr.id");
    $st->execute($a);
    $rows = $st->fetchAll();
    $statusLabel = ['pending' => 'Chờ thanh toán', 'paid' => 'Đã nhận tiền', 'cancelled' => 'Đã hủy'];
    if ($export) {
        $out = [];
        foreach ($rows as $i => $r) {
            $out[] = [$i + 1, vn_date($r['created_at'], true), $r['treatment_code'], (string)$r['patient_name'], (int)$r['amount'], $r['transfer_content'],
                $statusLabel[$r['status']], vn_date($r['paid_at'], true), (string)$r['ref_no'], (string)$r['confirm_note'], $r['account_no'], $r['creator']];
        }
        $out[] = [['Tổng đã nhận'], '', '', '', (int)array_sum(array_column(array_filter($rows, fn($r) => $r['status'] === 'paid'), 'amount')), '', '', '', '', '', '', ''];
        audit('report_export', "qr $from..$to");
        Xlsx::download("bao_cao_thanh_toan_qr_{$from}_{$to}.xlsx",
            ['STT', 'Thời gian tạo', 'Mã điều trị', 'Người bệnh', 'Số tiền', 'Nội dung CK', 'Trạng thái', 'Thời gian nhận', 'Số tham chiếu NH', 'Ghi chú xác nhận', 'Tài khoản nhận', 'Người tạo'],
            $out, 'BÁO CÁO THU TIỀN QUA MÃ QR – ' . mb_strtoupper($range));
    }
}

/* ---------- Tổng hợp theo ngày ---------- */
if ($tab === 'summary') {
    [$w1, $a1] = where_common('v', $from, $to, $userFilter);
    [$w2, $a2] = where_common('pr', $from, $to, $userFilter);
    $st = db()->prepare('SELECT DATE(v.created_at) d, COUNT(*) n, SUM(v.amount) amt FROM transport_vouchers v WHERE ' . implode(' AND ', $w1) . " AND v.status = 'active' GROUP BY DATE(v.created_at)");
    $st->execute($a1);
    $vByDay = array_column($st->fetchAll(), null, 'd');
    $st = db()->prepare('SELECT DATE(pr.created_at) d, COUNT(*) n, SUM(pr.amount) amt FROM payment_requests pr WHERE ' . implode(' AND ', $w2) . " AND pr.status = 'paid' GROUP BY DATE(pr.created_at)");
    $st->execute($a2);
    $qByDay = array_column($st->fetchAll(), null, 'd');
    $days = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (isset($vByDay[$d]) || isset($qByDay[$d])) {
            $days[] = $d;
        }
    }
    if ($export) {
        $out = array_map(fn($d) => [vn_date($d), (int)($qByDay[$d]['n'] ?? 0), (int)($qByDay[$d]['amt'] ?? 0), (int)($vByDay[$d]['n'] ?? 0), (int)($vByDay[$d]['amt'] ?? 0)], $days);
        $out[] = [['Tổng cộng'], (int)array_sum(array_column($qByDay, 'n')), (int)array_sum(array_column($qByDay, 'amt')), (int)array_sum(array_column($vByDay, 'n')), (int)array_sum(array_column($vByDay, 'amt'))];
        Xlsx::download("tong_hop_{$from}_{$to}.xlsx", ['Ngày', 'Số GD thu QR', 'Tiền thu QR', 'Số phiếu chi VC', 'Tiền chi VC'], $out, 'TỔNG HỢP THU CHI – ' . mb_strtoupper($range));
    }
}

$qsBase = fn(array $extra = []) => '?' . http_build_query(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to, 'user' => $isMod ? $userFilter : null, 'status' => get('status') ?: null, 'to_fac' => get('to_fac') ?: null], $extra));
render_header('Báo cáo');
?>
<ul class="nav nav-tabs mb-3">
  <?php foreach (['voucher' => 'Phiếu chi vận chuyển', 'qr' => 'Thu tiền qua QR', 'summary' => 'Tổng hợp theo ngày'] as $k => $l): ?>
    <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="?<?= e(http_build_query(['tab' => $k, 'from' => $from, 'to' => $to])) ?>"><?= e($l) ?></a></li>
  <?php endforeach; ?>
</ul>
<form class="card card-body mb-3"><input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-sm-3 col-lg-2"><label class="form-label small">Từ ngày</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
    <div class="col-sm-3 col-lg-2"><label class="form-label small">Đến ngày</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
    <?php if ($isMod): ?>
    <div class="col-sm-3 col-lg-2"><label class="form-label small">Người lập</label><select name="user" class="form-select form-select-sm"><option value="0">Tất cả</option>
      <?php foreach ($users as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $userFilter === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if ($tab === 'voucher'): ?>
    <div class="col-sm-3 col-lg-2"><label class="form-label small">Trạng thái</label><select name="status" class="form-select form-select-sm">
      <?php foreach (['active' => 'Còn hiệu lực', 'cancelled' => 'Đã hủy', 'all' => 'Tất cả'] as $k => $l): ?><option value="<?= $k ?>" <?= get('status', 'active') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-4 col-lg-2"><label class="form-label small">Nơi chuyển đi</label><input name="from_fac" class="form-control form-control-sm" value="<?= e(get('from_fac')) ?>"></div>
    <?php elseif ($tab === 'qr'): ?>
    <div class="col-sm-3 col-lg-2"><label class="form-label small">Trạng thái</label><select name="status" class="form-select form-select-sm">
      <?php foreach (['all' => 'Tất cả', 'paid' => 'Đã nhận tiền', 'pending' => 'Chờ thanh toán', 'cancelled' => 'Đã hủy'] as $k => $l): ?><option value="<?= $k ?>" <?= get('status', 'all') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Xem</button></div>
    <div class="col-auto ms-auto"><a class="btn btn-sm btn-success" href="<?= e($qsBase(['export' => 1])) ?>"><i class="bi bi-file-earmark-excel"></i> Xuất Excel</a></div>
  </div>
  <?php if (!$isMod): ?><div class="form-text">Bạn đang xem số liệu do chính mình lập.</div><?php endif; ?>
</form>

<?php if ($tab === 'voucher'): $sumAmt = array_sum(array_column($active, 'amount')); $sumKm = array_sum(array_column($active, 'distance_km')); ?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="label">Số phiếu còn hiệu lực</div><div class="value"><?= count($active) ?></div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="label">Tổng tiền hỗ trợ</div><div class="value text-primary"><?= money($sumAmt) ?> đ</div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="label">Tổng quãng đường</div><div class="value"><?= km_text($sumKm) ?> km</div></div></div></div>
</div>
<div class="row g-3">
  <div class="col-xl-4"><div class="card"><div class="card-header">Theo nơi chuyển đi</div><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Cơ sở</th><th class="num">Phiếu</th><th class="num">Số tiền</th></tr></thead>
    <tbody><?php foreach ($byFac as $k => $x): ?><tr><td class="small"><?= e($k) ?></td><td class="num"><?= $x['n'] ?></td><td class="num"><?= money($x['amount']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div></div></div>
  <div class="col-xl-8"><div class="card"><div class="card-header">Chi tiết (<?= count($rows) ?> phiếu)</div><div class="table-responsive" style="max-height:520px"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Số</th><th>Ngày</th><th>Người bệnh</th><th>Nơi chuyển đi</th><th class="num">Km</th><th class="num">Số tiền</th><th>Người lập</th></tr></thead>
    <tbody><?php foreach (array_slice($rows, 0, 1000) as $r): ?>
      <tr class="<?= $r['status'] === 'cancelled' ? 'text-muted text-decoration-line-through' : '' ?>"><td><a href="<?= e(url('payment_print.php?id=' . $r['id'])) ?>"><?= e($r['voucher_no']) ?></a></td><td class="small text-nowrap"><?= vn_date($r['created_at'], true) ?></td>
        <td><?= e($r['patient_name']) ?></td><td class="small"><?= e($r['from_facility']) ?></td><td class="num"><?= km_text($r['distance_km']) ?></td><td class="num"><?= money($r['amount']) ?></td><td class="small"><?= e($r['creator']) ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">Không có dữ liệu</td></tr><?php endif; ?></tbody>
  </table></div></div></div>
</div>
<?php elseif ($tab === 'qr'):
    $paid = array_filter($rows, fn($r) => $r['status'] === 'paid');
    $pend = array_filter($rows, fn($r) => $r['status'] === 'pending'); ?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="label">Đã nhận tiền</div><div class="value text-success"><?= money(array_sum(array_column($paid, 'amount'))) ?> đ</div><div class="small text-muted"><?= count($paid) ?> giao dịch</div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="label">Đang chờ</div><div class="value text-warning"><?= money(array_sum(array_column($pend, 'amount'))) ?> đ</div><div class="small text-muted"><?= count($pend) ?> yêu cầu</div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="label">Tổng số yêu cầu</div><div class="value"><?= count($rows) ?></div></div></div></div>
</div>
<div class="card"><div class="table-responsive" style="max-height:560px"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Thời gian</th><th>Mã điều trị</th><th>Người bệnh</th><th class="num">Số tiền</th><th>Trạng thái</th><th>Xác nhận</th><th>Người tạo</th></tr></thead>
  <tbody><?php foreach (array_slice($rows, 0, 1000) as $r): ?>
    <tr><td class="small text-nowrap"><?= vn_date($r['created_at'], true) ?></td><td><?= e($r['treatment_code']) ?></td><td><?= e($r['patient_name']) ?></td><td class="num"><?= money($r['amount']) ?></td>
      <td><span class="badge text-bg-<?= ['pending' => 'warning', 'paid' => 'success', 'cancelled' => 'secondary'][$r['status']] ?>"><?= e($statusLabel[$r['status']]) ?></span></td>
      <td class="small"><?= $r['paid_at'] ? vn_date($r['paid_at'], true) . ' · ' : '' ?><?= e($r['confirm_note']) ?></td><td class="small"><?= e($r['creator']) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">Không có dữ liệu</td></tr><?php endif; ?></tbody>
</table></div></div>
<?php else: ?>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Ngày</th><th class="num">Số GD thu QR</th><th class="num">Tiền thu QR</th><th class="num">Số phiếu chi VC</th><th class="num">Tiền chi VC</th></tr></thead>
  <tbody><?php foreach ($days as $d): ?>
    <tr><td><?= vn_date($d) ?></td><td class="num"><?= (int)($qByDay[$d]['n'] ?? 0) ?></td><td class="num"><?= money($qByDay[$d]['amt'] ?? 0) ?></td><td class="num"><?= (int)($vByDay[$d]['n'] ?? 0) ?></td><td class="num"><?= money($vByDay[$d]['amt'] ?? 0) ?></td></tr>
  <?php endforeach; if (!$days): ?><tr><td colspan="5" class="text-center text-muted py-3">Không có dữ liệu</td></tr><?php endif; ?></tbody>
  <tfoot class="fw-bold"><tr><td>Tổng cộng</td><td class="num"><?= array_sum(array_column($qByDay, 'n')) ?></td><td class="num"><?= money(array_sum(array_column($qByDay, 'amt'))) ?></td><td class="num"><?= array_sum(array_column($vByDay, 'n')) ?></td><td class="num"><?= money(array_sum(array_column($vByDay, 'amt'))) ?></td></tr></tfoot>
</table></div></div>
<?php endif; ?>
<?php render_footer();
