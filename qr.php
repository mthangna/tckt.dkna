<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$accounts = db()->query('SELECT id, bank_name, account_no, account_name, is_default FROM bank_accounts WHERE active = 1 ORDER BY is_default DESC, id')->fetchAll();
render_header('Thanh toán QR');
?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-pencil-square text-primary"></i> Thông tin thanh toán</div>
      <div class="card-body">
        <?php if (!$accounts): ?>
          <div class="alert alert-warning mb-0">Chưa có tài khoản nhận tiền. <?= has_role('mod') ? '<a href="' . e(url('settings_bank.php')) . '">Cấu hình ngay</a>.' : 'Liên hệ người quản lý để cấu hình.' ?></div>
        <?php else: ?>
        <form id="qr-form" autocomplete="off">
          <div class="mb-3">
            <label class="form-label">Tài khoản nhận tiền</label>
            <?php if (count($accounts) > 1): ?>
            <select name="bank_account_id" class="form-select mb-2" id="qr-account">
              <?php foreach ($accounts as $a): ?>
                <option value="<?= (int)$a['id'] ?>" data-bank="<?= e($a['bank_name']) ?>" data-no="<?= e($a['account_no']) ?>" data-name="<?= e($a['account_name']) ?>"><?= e($a['bank_name'] . ' - ' . $a['account_no']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <div class="acc-card" id="acc-card">
              <div><span class="text-muted">Ngân hàng:</span> <b data-acc="bank"><?= e($accounts[0]['bank_name']) ?></b></div>
              <div><span class="text-muted">Tên tài khoản:</span> <b data-acc="name"><?= e($accounts[0]['account_name']) ?></b></div>
              <div><span class="text-muted">Số tài khoản:</span> <b class="fs-5" data-acc="no"><?= e($accounts[0]['account_no']) ?></b></div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Mã điều trị <span class="text-danger">*</span></label>
            <input name="treatment_code" class="form-control text-uppercase" maxlength="20" required placeholder="VD: 2610001234">
          </div>
          <div class="mb-3">
            <label class="form-label">Họ tên người bệnh <span class="text-danger">*</span></label>
            <input name="patient_name" class="form-control text-uppercase" maxlength="150" required placeholder="VD: Nguyễn Văn An">
          </div>
          <div class="mb-3">
            <label class="form-label">Số tiền (VNĐ) <span class="text-danger">*</span></label>
            <input name="amount" class="form-control form-control-lg fw-bold" data-money inputmode="numeric" required placeholder="0">
          </div>
          <div class="small mb-3">Nội dung chuyển khoản: <code id="qr-content-preview">mã điều trị + họ tên không dấu</code></div>
          <button class="btn btn-primary w-100"><i class="bi bi-qr-code"></i> Tạo mã QR</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-phone text-primary"></i> Mã VietQR</span>
        <span id="qr-status-badge"></span>
      </div>
      <div class="card-body">
        <div class="row align-items-center">
          <div class="col-md-6 text-center">
            <div class="qr-box" id="qr-box"><div class="text-muted"><i class="bi bi-qr-code fs-1"></i><br>Nhập thông tin để tạo mã</div></div>
          </div>
          <div class="col-md-6 mt-3 mt-md-0" id="qr-info" hidden>
            <dl class="row mb-2">
              <dt class="col-5">Ngân hàng</dt><dd class="col-7 fw-semibold" data-f="bank"></dd>
              <dt class="col-5">Tên tài khoản</dt><dd class="col-7 fw-semibold" data-f="account_name"></dd>
              <dt class="col-5">Số tài khoản</dt><dd class="col-7 fw-bold fs-5 text-primary" data-f="account_no"></dd>
              <dt class="col-5">Mã điều trị</dt><dd class="col-7" data-f="treatment_code"></dd>
              <dt class="col-5">Người bệnh</dt><dd class="col-7" data-f="patient_name"></dd>
              <dt class="col-5">Nội dung CK</dt><dd class="col-7"><code class="fs-6" data-f="content"></code></dd>
            </dl>
            <div class="fs-3 fw-bold text-primary mb-2" data-f="amount"></div>
            <div class="qr-status alert mb-2" id="qr-status"></div>
            <div class="d-flex flex-wrap gap-2">
              <button class="btn btn-outline-secondary btn-sm" id="btn-cancel"><i class="bi bi-x-circle"></i> Hủy yêu cầu</button>
              <?php if (has_role('mod')): ?>
              <button class="btn btn-outline-success btn-sm" id="btn-confirm"><i class="bi bi-check2-square"></i> Xác nhận thủ công</button>
              <?php endif; ?>
              <?php if (setting('demo_mode') === '1'): ?>
              <button class="btn btn-warning btn-sm" id="btn-demo-pay" title="Chỉ có ở chế độ demo"><i class="bi bi-cone-striped"></i> Giả lập tiền về</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-clock-history"></i> Yêu cầu thanh toán trong ngày <?= has_role('mod') ? '(toàn bộ)' : '(của tôi)' ?></span>
    <button class="btn btn-sm btn-light" id="btn-reload"><i class="bi bi-arrow-clockwise"></i></button>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle">
      <thead><tr><th>Giờ tạo</th><th>Mã điều trị</th><th>Người bệnh</th><th class="num">Số tiền</th><th>Trạng thái</th><th>Người tạo</th></tr></thead>
      <tbody id="qr-list"><tr><td colspan="6" class="text-center text-muted py-3">Đang tải...</td></tr></tbody>
    </table>
  </div>
</div>
<?php render_footer(['assets/vendor/qrcode/qrcode.js', 'assets/js/qr.js']);
