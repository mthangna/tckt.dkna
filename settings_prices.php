<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/prices.php';
$u = require_role('mod');

$tmpDir = APP_ROOT . '/storage/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
// Dọn file tạm cũ hơn 1 ngày
foreach (glob($tmpDir . '/import_*.json') ?: [] as $f) {
    if (filemtime($f) < time() - 86400) {
        @unlink($f);
    }
}

if (get('template') === '1') {
    if (get('cat') === 'khac') {
        Xlsx::download('mau_danh_muc_gia_dich_vu_khac.xlsx',
            ['STT', 'Mã dịch vụ', 'Tên dịch vụ', 'Đơn vị tính', 'Đơn giá', 'Quyết định', 'Ngày ban hành', 'Ngày áp dụng', 'Ghi chú'],
            [
                [1, 'DVK001', 'Trông giữ xe máy', 'lượt', 5000, 'Quyết định số .../QĐ-BV', '15/06/2026', '01/07/2026', ''],
                [2, 'DVK002', 'Sao y bệnh án', 'bộ', 50000, '', '', '', 'Để trống quyết định/ngày → dùng giá trị mặc định khi tải lên'],
            ]);
    }
    Xlsx::download('mau_danh_muc_gia_kcb.xlsx',
        ['STT', 'Mã tương đương', 'Mã kỹ thuật', 'Tên dịch vụ', 'Đơn vị tính', 'Đơn giá', 'Quyết định', 'Ngày ban hành', 'Ngày áp dụng', 'Ghi chú'],
        [
            [1, '02.0001.0001', 'KT00001', 'Khám Nội', 'lần', 38700, 'Quyết định số .../QĐ-SYT', '15/06/2026', '01/07/2026', ''],
            [2, '18.0001.0001', 'KT00002', 'Siêu âm ổ bụng', 'lần', 49300, '', '', '', 'Để trống quyết định/ngày → dùng giá trị mặc định khi tải lên'],
        ]);
}

$preview = null;
if (is_post()) {
    csrf_check();
    $act = post('act');
    if ($act === 'upload') {
        $cat = post('category');
        $file = $_FILES['file'] ?? null;
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $defaults = [
            'decision_name'  => post('decision_name'),
            'decision_date'  => parse_date(post('decision_date')),
            'effective_from' => parse_date(post('effective_from')),
        ];
        if (!in_array($cat, price_categories(), true)) {
            flash('danger', 'Chọn nhóm giá.');
        } elseif (!$file || !is_uploaded_file($file['tmp_name']) || !in_array($ext, ['xlsx', 'csv'], true)) {
            flash('danger', 'Chọn file .xlsx hoặc .csv. Nếu là file .xls cũ, hãy mở bằng Excel và lưu lại dạng .xlsx.');
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            flash('danger', 'File quá lớn (tối đa 10 MB).');
        } else {
            try {
                $parsed = PriceImport::parse($file['tmp_name'], $ext, $defaults, $cat);
                $plan = PriceImport::plan($cat, $parsed['rows']);
                $token = bin2hex(random_bytes(12));
                file_put_contents("$tmpDir/import_$token.json", json_encode([
                    'user' => $u['id'], 'category' => $cat, 'file' => $file['name'],
                    'plan' => $plan, 'errors' => $parsed['errors'], 'columns' => $parsed['columns'],
                ], JSON_UNESCAPED_UNICODE));
                header('Location: ' . url('settings_prices.php?preview=' . $token));
                exit;
            } catch (Throwable $e) {
                flash('danger', $e->getMessage());
            }
        }
        redirect('settings_prices.php');
    }
    if ($act === 'confirm') {
        $token = preg_replace('/[^a-f0-9]/', '', post('token'));
        $path = "$tmpDir/import_$token.json";
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (!$data || (int)$data['user'] !== (int)$u['id']) {
            flash('danger', 'Phiên nhập dữ liệu đã hết hạn, vui lòng tải file lên lại.');
            redirect('settings_prices.php');
        }
        try {
            $count = PriceImport::apply($data['category'], $data['plan'], post('close_missing') === '1', $data['file'], (int)$u['id']);
            @unlink($path);
            audit('price_import', category_label($data['category']) . ' ' . $data['file'] . ' ' . json_encode($count));
            flash('success', sprintf('Đã cập nhật %s: %d mục mới, %d mục đổi giá, %d mục sửa thông tin, %d mục chuyển hết hiệu lực.',
                category_label($data['category']), $count['new'], $count['update'], $count['fix'], $count['closed']));
        } catch (Throwable $e) {
            flash('danger', 'Lỗi khi ghi dữ liệu: ' . $e->getMessage());
        }
        redirect('settings_prices.php');
    }
}

if (($token = preg_replace('/[^a-f0-9]/', '', get('preview'))) !== '') {
    $path = "$tmpDir/import_$token.json";
    $preview = is_file($path) ? json_decode(file_get_contents($path), true) : null;
    if ($preview && (int)$preview['user'] !== (int)$u['id']) {
        $preview = null;
    }
}

$batches = db()->query('SELECT b.*, us.full_name FROM price_batches b LEFT JOIN users us ON us.id = b.uploaded_by ORDER BY b.id DESC LIMIT 20')->fetchAll();
$actionLabel = ['new' => ['success', 'Thêm mới'], 'update' => ['primary', 'Giá mới'], 'fix' => ['info', 'Sửa thông tin'], 'same' => ['light', 'Không đổi'], 'skip' => ['danger', 'Bỏ qua']];

render_header('Danh mục giá dịch vụ (Excel)');

if ($preview):
    $cnt = array_fill_keys(array_keys($actionLabel), 0);
    foreach ($preview['plan']['items'] as $p) {
        $cnt[$p['action']]++;
    }
?>
<div class="card mb-4">
  <div class="card-header">Xem trước: <?= e(category_label($preview['category'])) ?> · <?= e($preview['file']) ?></div>
  <div class="card-body">
    <div class="d-flex flex-wrap gap-2 mb-3">
      <?php foreach ($cnt as $k => $n): ?><span class="badge text-bg-<?= $actionLabel[$k][0] ?> fs-6 fw-normal"><?= $actionLabel[$k][1] ?>: <?= $n ?></span><?php endforeach; ?>
      <span class="badge text-bg-warning fs-6 fw-normal">Không có trong file: <?= count($preview['plan']['missing']) ?></span>
    </div>
    <p class="small text-muted mb-2">Cột đã nhận diện: <?= e(implode(', ', $preview['columns'])) ?>.</p>
    <?php if ($preview['errors']): ?>
      <div class="alert alert-danger small"><strong><?= count($preview['errors']) ?> dòng lỗi (sẽ bị bỏ qua):</strong><br><?= implode('<br>', array_map('e', array_slice($preview['errors'], 0, 30))) ?><?= count($preview['errors']) > 30 ? '<br>...' : '' ?></div>
    <?php endif; ?>
    <div class="table-responsive" style="max-height:420px">
      <table class="table table-sm">
        <thead><tr><th>Dòng</th><th>Thao tác</th><th><?= $preview['category'] === 'khac' ? 'Mã DV' : 'Mã KT' ?></th><th>Tên dịch vụ</th><th class="num">Giá cũ</th><th class="num">Giá mới</th><th>Áp dụng từ</th><th>Ghi chú</th></tr></thead>
        <tbody>
        <?php foreach (array_slice(array_filter($preview['plan']['items'], fn($p) => $p['action'] !== 'same'), 0, 500) as $p): $r = $p['row']; ?>
          <tr><td><?= (int)$r['row'] ?></td><td><span class="badge text-bg-<?= $actionLabel[$p['action']][0] ?>"><?= $actionLabel[$p['action']][1] ?></span></td>
            <td class="small"><?= e($preview['category'] === 'khac' ? $r['equiv_code'] : $r['tech_code']) ?></td><td><?= e($r['name']) ?></td>
            <td class="num"><?= isset($p['old_price']) ? money($p['old_price']) : '' ?></td><td class="num fw-semibold"><?= money($r['price']) ?></td>
            <td><?= vn_date($r['effective_from']) ?></td><td class="small text-danger"><?= e($p['reason'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" class="mt-3"><?= csrf_field() ?>
      <input type="hidden" name="act" value="confirm"><input type="hidden" name="token" value="<?= e($token) ?>">
      <?php if ($preview['plan']['missing']): ?>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="close_missing" value="1" id="cm">
        <label class="form-check-label" for="cm">Thay thế toàn bộ: chuyển <strong><?= count($preview['plan']['missing']) ?></strong> mục đang hiệu lực nhưng <strong>không có trong file</strong> sang hết hiệu lực (từ trước ngày áp dụng sớm nhất trong file)</label>
      </div>
      <?php endif; ?>
      <button class="btn btn-success"><i class="bi bi-check2"></i> Xác nhận cập nhật</button>
      <a class="btn btn-light" href="<?= e(url('settings_prices.php')) ?>">Hủy</a>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><i class="bi bi-upload"></i> Tải danh mục giá lên</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
          <input type="hidden" name="act" value="upload">
          <div class="mb-3"><label class="form-label">Nhóm giá</label>
            <select name="category" class="form-select" required>
              <?php foreach (price_categories() as $c): ?><option value="<?= $c ?>"><?= e(category_label($c)) ?></option><?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label">File Excel (.xlsx) hoặc CSV</label><input type="file" name="file" class="form-control" accept=".xlsx,.csv" required>
            <div class="form-text">Tải file mẫu: <a href="?template=1"><i class="bi bi-download"></i> giá KCB (BHYT, theo yêu cầu)</a> · <a href="?template=1&amp;cat=khac"><i class="bi bi-download"></i> giá dịch vụ khác</a>. Cột được nhận diện theo tiêu đề, không cần đúng thứ tự.</div></div>
          <fieldset class="border rounded p-3 mb-3">
            <legend class="float-none w-auto px-2 fs-6 mb-0">Giá trị mặc định (dùng khi ô trong file để trống)</legend>
            <div class="mb-2"><label class="form-label small">Quyết định ban hành giá</label><input name="decision_name" class="form-control form-control-sm" placeholder="VD: Quyết định số 2345/QĐ-SYT ngày 15/06/2026"></div>
            <div class="row g-2">
              <div class="col"><label class="form-label small">Ngày ban hành</label><input type="date" name="decision_date" class="form-control form-control-sm"></div>
              <div class="col"><label class="form-label small">Ngày áp dụng</label><input type="date" name="effective_from" class="form-control form-control-sm"></div>
            </div>
          </fieldset>
          <button class="btn btn-primary"><i class="bi bi-eye"></i> Đọc file và xem trước</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><i class="bi bi-info-circle"></i> Cách hệ thống cập nhật giá</div>
      <div class="card-body small">
        <ul class="mb-0">
          <li>Mỗi dịch vụ được nhận diện theo <strong>mã tương đương</strong> (nếu không có thì theo mã kỹ thuật, rồi theo tên).</li>
          <li>Dịch vụ có <strong>giá mới với ngày áp dụng muộn hơn</strong>: giá cũ tự chuyển sang hết hiệu lực từ ngày trước đó và vẫn được lưu để tra cứu lại.</li>
          <li>Cùng ngày áp dụng nhưng khác thông tin: coi là sửa sai, cập nhật vào bản ghi hiện tại.</li>
          <li>Dòng không có giá (dòng tiêu đề nhóm) được bỏ qua.</li>
          <li>Luôn có bước xem trước, chỉ ghi vào CSDL khi bấm xác nhận.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header"><i class="bi bi-clock-history"></i> Lịch sử tải lên</div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Thời gian</th><th>Nhóm</th><th>File</th><th class="num">Số mục ghi</th><th>Chi tiết</th><th>Người tải</th></tr></thead>
    <tbody>
    <?php foreach ($batches as $b): $n = json_decode((string)$b['note'], true) ?: []; ?>
      <tr><td><?= vn_date($b['uploaded_at'], true) ?></td><td><?= e(category_label($b['category'])) ?></td><td><?= e($b['file_name']) ?></td><td class="num"><?= (int)$b['row_count'] ?></td>
        <td class="small">mới <?= (int)($n['new'] ?? 0) ?>, đổi giá <?= (int)($n['update'] ?? 0) ?>, sửa <?= (int)($n['fix'] ?? 0) ?>, hết HL <?= (int)($n['closed'] ?? 0) ?></td><td><?= e($b['full_name']) ?></td></tr>
    <?php endforeach; if (!$batches): ?><tr><td colspan="6" class="text-center text-muted py-3">Chưa có lần tải lên nào</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
