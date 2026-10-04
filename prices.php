<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_login();
$counts = [];
foreach (db()->query('SELECT category, COUNT(*) n FROM price_items WHERE effective_from <= CURDATE() AND (effective_to IS NULL OR effective_to >= CURDATE()) GROUP BY category') as $r) {
    $counts[$r['category']] = (int)$r['n'];
}
render_header('Tra cứu bảng giá');
?>
<div class="card">
  <div class="card-header p-0 border-bottom-0">
    <ul class="nav nav-tabs px-3 pt-2" id="price-tabs">
      <?php foreach (price_categories() as $i => $c): ?>
        <li class="nav-item"><button class="nav-link<?= $i === 0 ? ' active' : '' ?>" data-cat="<?= $c ?>"><?= e(category_label($c)) ?>
          <span class="badge rounded-pill text-bg-light"><?= $counts[$c] ?? 0 ?></span></button></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card-body border-top">
    <div class="row g-2 align-items-center mb-3">
      <div class="col-md-5">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input id="price-q" class="form-control" placeholder="Tìm theo tên (có dấu hoặc không dấu), mã kỹ thuật, mã tương đương, mã dịch vụ...">
        </div>
      </div>
      <div class="col-auto">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" id="price-all">
          <label class="form-check-label" for="price-all">Xem cả giá cũ hết hiệu lực</label>
        </div>
      </div>
      <div class="col-auto ms-md-auto d-flex align-items-center gap-2">
        <label class="small text-muted" for="price-per">Hiển thị</label>
        <select id="price-per" class="form-select form-select-sm" style="width:auto">
          <option>20</option><option>50</option><option>100</option>
        </select>
        <span class="small text-muted">mục/trang</span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover table-sm align-middle">
        <thead><tr><th style="width:60px">STT</th><th style="width:150px" id="price-code-head">Mã kỹ thuật</th><th>Tên dịch vụ</th><th style="width:80px">ĐVT</th><th class="num" style="width:140px">Đơn giá (đ)</th><th style="width:120px">Áp dụng từ</th></tr></thead>
        <tbody id="price-body"><tr><td colspan="6" class="text-center py-4 text-muted">Đang tải...</td></tr></tbody>
      </table>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="small text-muted" id="price-summary"></div>
      <nav><ul class="pagination pagination-sm mb-0" id="price-pages"></ul></nav>
    </div>
    <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> Rê chuột lên một dòng để xem quyết định ban hành, ngày áp dụng và các mã; bấm vào dòng để xem lịch sử giá.</div>
  </div>
</div>

<div class="modal fade" id="price-modal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Chi tiết giá dịch vụ</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="price-modal-body"></div>
    </div>
  </div>
</div>
<?php render_footer(['assets/js/prices.js']);
