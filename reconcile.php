<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/payments.php';
$u = require_role('mod');

if (is_post()) {
    csrf_check();
    $act = post('act');
    if ($act === 'upload' && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            flash('danger', 'Chỉ nhận file .xlsx hoặc .csv. Nếu file sao kê là .xls, hãy mở bằng Excel và lưu lại dạng .xlsx.');
        } else {
            try {
                $rows = parse_statement($_FILES['file']['tmp_name'], $ext);
                $new = $dup = $matched = 0;
                foreach ($rows as $r) {
                    $res = record_bank_txn('statement', $r['ref'], null, $r['time'], $r['amount'], $r['content']);
                    $res['duplicate'] ? $dup++ : $new++;
                    if (!$res['duplicate'] && $res['matched']) {
                        $matched++;
                    }
                }
                audit('statement_upload', $_FILES['file']['name'] . " new=$new dup=$dup matched=$matched");
                flash('success', "Đã đọc " . count($rows) . " giao dịch tiền vào: $new mới, $dup đã có trước đó, $matched giao dịch khớp tự động với yêu cầu QR.");
            } catch (Throwable $e) {
                flash('danger', $e->getMessage());
            }
        }
    } elseif ($act === 'match') {
        $txnId = (int)post('txn_id');
        $reqId = (int)post('request_id');
        if ($txnId && $reqId && mark_paid($reqId, $txnId, 'Khớp thủ công bởi ' . $u['full_name'])) {
            audit('txn_match_manual', "txn=$txnId req=$reqId");
            flash('success', 'Đã khớp giao dịch với yêu cầu thanh toán.');
        } else {
            flash('danger', 'Không khớp được (yêu cầu có thể đã được thanh toán hoặc bị hủy).');
        }
    } elseif ($act === 'rematch') {
        $n = 0;
        foreach (db()->query('SELECT id FROM bank_transactions WHERE payment_request_id IS NULL')->fetchAll() as $t) {
            if (match_bank_txn((int)$t['id'])) {
                $n++;
            }
        }
        flash('info', "Đã chạy lại đối soát: $n giao dịch được khớp thêm.");
    }
    redirect('reconcile.php');
}

$unmatched = db()->query("SELECT * FROM bank_transactions WHERE payment_request_id IS NULL AND txn_time > (NOW() - INTERVAL 60 DAY) ORDER BY txn_time DESC LIMIT 200")->fetchAll();
$pending = db()->query("SELECT id, treatment_code, patient_name, amount, transfer_content, created_at FROM payment_requests WHERE status = 'pending' AND created_at > (NOW() - INTERVAL 60 DAY) ORDER BY created_at DESC")->fetchAll();
$pendingByAmount = [];
foreach ($pending as $p) {
    $pendingByAmount[(string)(int)$p['amount']][] = $p;
}
$srcLabel = ['webhook' => 'Webhook', 'statement' => 'Sao kê', 'demo' => 'Demo'];

render_header('Đối soát ngân hàng');
?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-upload"></i> Tải sao kê từ Internet Banking</div>
      <div class="card-body">
        <p class="small text-muted">Dùng khi chưa kết nối API ngân hàng hoặc để đối soát cuối ngày. Tải sao kê tài khoản từ BIDV iBank (Excel), hệ thống sẽ đọc các giao dịch tiền vào và tự khớp với yêu cầu QR theo <strong>nội dung + số tiền</strong>. Giao dịch đã nạp trước đó sẽ không bị nạp trùng.</p>
        <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
          <input type="hidden" name="act" value="upload">
          <input type="file" name="file" class="form-control mb-2" accept=".xlsx,.csv" required>
          <button class="btn btn-primary"><i class="bi bi-arrow-repeat"></i> Đọc và đối soát</button>
        </form>
        <hr>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="rematch">
          <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-magic"></i> Chạy lại khớp tự động cho các giao dịch chưa khớp</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-hourglass-split"></i> Yêu cầu QR đang chờ tiền (60 ngày gần nhất): <?= count($pending) ?></div>
      <div class="table-responsive" style="max-height:320px">
        <table class="table table-sm mb-0">
          <thead><tr><th>Thời gian</th><th>Mã điều trị</th><th>Người bệnh</th><th class="num">Số tiền</th></tr></thead>
          <tbody>
          <?php foreach ($pending as $p): ?>
            <tr><td><?= vn_date($p['created_at'], true) ?></td><td><?= e($p['treatment_code']) ?></td><td><?= e($p['patient_name']) ?></td><td class="num"><?= money($p['amount']) ?></td></tr>
          <?php endforeach; if (!$pending): ?><tr><td colspan="4" class="text-muted text-center py-3">Không có</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header"><i class="bi bi-exclamation-diamond text-warning"></i> Giao dịch tiền vào chưa khớp (60 ngày gần nhất)</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Thời gian</th><th>Nguồn</th><th class="num">Số tiền</th><th>Nội dung chuyển khoản</th><th style="width:330px">Khớp thủ công</th></tr></thead>
      <tbody>
      <?php foreach ($unmatched as $t): $cands = $pendingByAmount[(string)(int)$t['amount']] ?? []; ?>
        <tr>
          <td class="text-nowrap"><?= vn_date($t['txn_time'], true) ?></td>
          <td><span class="badge text-bg-light"><?= e($srcLabel[$t['source']] ?? $t['source']) ?></span></td>
          <td class="num fw-semibold"><?= money($t['amount']) ?></td>
          <td class="small"><?= e($t['content']) ?></td>
          <td>
            <?php if ($cands): ?>
            <form method="post" class="d-flex gap-1"><?= csrf_field() ?>
              <input type="hidden" name="act" value="match"><input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">
              <select name="request_id" class="form-select form-select-sm">
                <?php foreach ($cands as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e($c['treatment_code'] . ' · ' . ($c['patient_name'] ?: '') . ' · ' . vn_date($c['created_at'], true)) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-success" data-confirm="Xác nhận khớp giao dịch này với yêu cầu đã chọn?">Khớp</button>
            </form>
            <?php else: ?><span class="small text-muted">Không có yêu cầu chờ cùng số tiền</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if (!$unmatched): ?>
        <tr><td colspan="5" class="text-center text-muted py-3">Tất cả giao dịch đã được khớp.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer();
