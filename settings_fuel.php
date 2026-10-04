<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fuel.php';
$u = require_role('mod');

if (is_post()) {
    csrf_check();
    if (post('act') === 'price') {
        $price = (int)preg_replace('/\D/', '', post('price'));
        $eff = post('effective_at');
        $effTs = $eff !== '' ? strtotime($eff) : time();
        $name = post('fuel_name') ?: (string)setting('fuel_name');
        if ($price < 5000 || $price > 100000 || !$effTs) {
            flash('danger', 'Giá xăng không hợp lệ (5.000–100.000 đ/lít).');
        } else {
            db()->prepare('INSERT INTO fuel_prices (fuel_name, price, effective_at, source, created_by) VALUES (?,?,?,?,?)')
                ->execute([$name, $price, date('Y-m-d H:i:s', $effTs), post('source') ?: 'Nhập tay', $u['id']]);
            audit('fuel_price_set', "$name $price từ " . date('d/m/Y H:i', $effTs));
            flash('success', 'Đã cập nhật giá xăng ' . money($price) . ' đ/lít, áp dụng từ ' . date('d/m/Y H:i', $effTs) . '.');
        }
    } elseif (post('act') === 'source') {
        $src = post('fuel_source_url');
        if ($src !== '' && !preg_match('#^https?://#i', $src)) {
            flash('danger', 'Địa chỉ nguồn phải bắt đầu bằng http:// hoặc https://');
        } else {
            setting_set('fuel_source_url', $src);
            setting_set('fuel_keyword', post('fuel_keyword'));
            setting_set('fuel_name', post('fuel_name_default'));
            setting_set('fuel_zone', post('fuel_zone') === '2' ? '2' : '1');
            flash('success', 'Đã lưu nguồn tham khảo.');
        }
    }
    redirect('settings_fuel.php');
}
$current = fuel_current();
$history = db()->query('SELECT f.*, us.full_name FROM fuel_prices f LEFT JOIN users us ON us.id = f.created_by ORDER BY f.effective_at DESC, f.id DESC LIMIT 30')->fetchAll();
render_header('Cài đặt giá xăng');
?>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-fuel-pump"></i> Giá xăng dùng để tính tiền hỗ trợ vận chuyển</div>
      <div class="card-body">
        <div class="mb-3">Đang áp dụng: <?php if ($current): ?><span class="fs-4 fw-bold text-primary"><?= money($current['price']) ?> đ/lít</span>
          <span class="text-muted">(<?= e($current['fuel_name']) ?>, từ <?= vn_date($current['effective_at'], true) ?>)</span><?php else: ?><span class="text-danger">chưa có</span><?php endif; ?></div>
        <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="act" value="price">
          <input type="hidden" name="source" id="fuel-source" value="">
          <div class="col-md-4"><label class="form-label">Loại xăng</label><input name="fuel_name" class="form-control" value="<?= e(setting('fuel_name')) ?>"></div>
          <div class="col-md-3"><label class="form-label">Giá mới (đ/lít)</label><input name="price" id="fuel-price" class="form-control fw-bold" data-money required></div>
          <div class="col-md-3"><label class="form-label">Áp dụng từ</label><input name="effective_at" type="datetime-local" class="form-control" value="<?= date('Y-m-d\TH:i') ?>"></div>
          <div class="col-md-2"><button class="btn btn-primary w-100">Lưu</button></div>
        </form>
        <div class="form-text">Giá mới không ghi đè giá cũ: các phiếu chi đã lập vẫn giữ giá tại thời điểm lập; phiếu lập sau thời điểm áp dụng sẽ dùng giá mới.</div>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Lịch sử giá xăng</div>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Áp dụng từ</th><th>Loại</th><th class="num">Giá</th><th>Nguồn</th><th>Người cập nhật</th></tr></thead>
        <tbody><?php foreach ($history as $h): ?>
          <tr><td><?= vn_date($h['effective_at'], true) ?></td><td><?= e($h['fuel_name']) ?></td><td class="num fw-semibold"><?= money($h['price']) ?></td><td class="small"><?= e($h['source']) ?></td><td class="small"><?= e($h['full_name']) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card mb-4 border-info">
      <div class="card-header"><i class="bi bi-globe2"></i> Giá tham khảo từ Internet</div>
      <div class="card-body">
        <button class="btn btn-info text-white" id="btn-fetch"><i class="bi bi-cloud-download"></i> Lấy giá <?= e(setting('fuel_keyword')) ?> hiện hành</button>
        <div id="fetch-result" class="mt-3 small text-muted">Bấm nút để lấy giá bán lẻ mới nhất. Giá lấy về chỉ để tham khảo; chỉ được áp dụng khi bấm "Dùng giá này" rồi Lưu.</div>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Nguồn tham khảo</div>
      <div class="card-body">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="source">
          <div class="mb-2"><label class="form-label small">Địa chỉ trang có bảng giá</label><input name="fuel_source_url" class="form-control form-control-sm" value="<?= e(setting('fuel_source_url')) ?>"></div>
          <div class="row g-2 mb-2">
            <div class="col-7"><label class="form-label small">Từ khoá dòng cần lấy</label><input name="fuel_keyword" class="form-control form-control-sm" value="<?= e(setting('fuel_keyword')) ?>"></div>
            <div class="col-5"><label class="form-label small">Vùng giá</label><select name="fuel_zone" class="form-select form-select-sm"><option value="1">Vùng 1</option><option value="2" <?= setting('fuel_zone') === '2' ? 'selected' : '' ?>>Vùng 2</option></select></div>
          </div>
          <div class="mb-2"><label class="form-label small">Tên loại xăng mặc định</label><input name="fuel_name_default" class="form-control form-control-sm" value="<?= e(setting('fuel_name')) ?>"></div>
          <button class="btn btn-sm btn-outline-primary">Lưu nguồn</button>
        </form>
        <div class="form-text mt-2">Lưu ý: xăng RON 92 hiện chỉ còn bán dưới dạng <strong>xăng sinh học E5 RON 92</strong>. Nếu trang nguồn đổi cấu trúc, chỉ cần đổi địa chỉ hoặc từ khoá ở đây.</div>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const zone = <?= json_encode(setting('fuel_zone') === '2' ? 2 : 1) ?>;
  const out = document.getElementById('fetch-result');
  document.getElementById('btn-fetch').addEventListener('click', async (ev) => {
    const btn = ev.currentTarget; btn.disabled = true;
    out.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Đang lấy giá...';
    try {
      const r = await App.api('api/fuel_fetch.php');
      const pick = zone === 2 && r.zone2 ? r.zone2 : r.zone1;
      out.innerHTML = '<div class="fw-semibold text-dark">' + App.esc(r.label) + '</div>' +
        '<div class="fs-5">Vùng 1: <strong>' + App.money(r.zone1) + '</strong>' + (r.zone2 ? ' · Vùng 2: <strong>' + App.money(r.zone2) + '</strong>' : '') + ' đ/lít</div>' +
        (r.updated_at ? '<div class="text-muted">Nguồn cập nhật giá lúc ' + App.esc(r.updated_at) + '</div>' : '') +
        '<div class="text-muted">Lấy lúc ' + App.esc(r.fetched_at) + ' từ <a target="_blank" rel="noopener" href="' + App.esc(r.source) + '">nguồn</a></div>' +
        '<button class="btn btn-sm btn-success mt-2" id="btn-use">Dùng giá vùng ' + zone + ' (' + App.money(pick) + ')</button>';
      document.getElementById('btn-use').onclick = () => {
        const inp = document.getElementById('fuel-price');
        inp.value = App.money(pick);
        document.getElementById('fuel-source').value = 'Tham khảo ' + r.source + ' lúc ' + r.fetched_at;
        inp.focus();
      };
    } catch (e) { out.innerHTML = '<span class="text-danger">' + App.esc(e.message) + '</span>'; }
    finally { btn.disabled = false; }
  });
});
</script>
<?php render_footer();
