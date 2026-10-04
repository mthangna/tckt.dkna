<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/xlsx.php';
require_role('mod');

/* Cột của file Excel danh sách cơ sở (nhận diện theo tiêu đề, không cần đúng thứ tự) */
const FACILITY_COLUMNS = [
    'code'     => ['ma co so', 'ma cskcb', 'ma kcb', 'ma benh vien', 'ma bv', 'ma'],
    'name'     => ['ten co so', 'ten cskcb', 'ten benh vien', 'ten don vi', 'ten'],
    'address'  => ['dia chi'],
    'distance' => ['khoang cach', 'so km', 'km'],
];

if (get('template') === '1') {
    Xlsx::download('mau_danh_sach_co_so_kcb.xlsx', ['STT', 'Mã cơ sở', 'Tên cơ sở', 'Địa chỉ', 'Khoảng cách (km)'], [
        [1, '01929', 'Bệnh viện Bạch Mai', '78 Giải Phóng, Đống Đa, Hà Nội', 295],
        [2, '40002', 'Bệnh viện Sản Nhi Nghệ An', 'Nghi Phú, TP. Vinh, Nghệ An', 6.5],
    ]);
}

/** Đọc file Excel/CSV danh sách cơ sở; trả về [rows, errors] */
function facility_parse(string $path, string $ext): array
{
    $raw = $ext === 'csv' ? Xlsx::readCsv($path) : Xlsx::readRows($path);
    $headerIdx = null;
    $map = [];
    foreach ($raw as $i => $line) {
        if ($i > 20) break;
        $map = [];
        foreach ($line as $col => $cell) {
            $h = vn_unaccent((string)$cell);
            $best = null; $bestLen = 0;
            foreach (FACILITY_COLUMNS as $field => $keys) {
                foreach ($keys as $kw) {
                    if (strlen($kw) > $bestLen && preg_match('/(^|[^a-z0-9])' . preg_quote($kw, '/') . '([^a-z0-9]|$)/', $h)) {
                        $best = $field; $bestLen = strlen($kw);
                    }
                }
            }
            if ($best !== null && !isset($map[$best])) $map[$best] = $col;
        }
        if (isset($map['name'])) { $headerIdx = $i; break; }
    }
    if ($headerIdx === null) {
        throw new RuntimeException('Không tìm thấy dòng tiêu đề. File cần có ít nhất cột "Tên cơ sở" (xem file mẫu).');
    }
    $rows = []; $errors = [];
    foreach ($raw as $i => $line) {
        if ($i <= $headerIdx) continue;
        $get = fn(string $k) => isset($map[$k]) ? trim((string)($line[$map[$k]] ?? '')) : '';
        $name = $get('name');
        if ($name === '' && $get('code') === '') continue;
        if ($name === '') { $errors[] = 'Dòng ' . ($i + 1) . ': thiếu tên cơ sở.'; continue; }
        $d = str_replace(',', '.', preg_replace('/\s*km\s*$/iu', '', $get('distance')));
        if ($d !== '' && (!is_numeric($d) || (float)$d < 0 || (float)$d > 5000)) {
            $errors[] = 'Dòng ' . ($i + 1) . ': khoảng cách không hợp lệ (' . $get('distance') . ').';
            continue;
        }
        $code = $get('code');
        if (preg_match('/^\d+\.0+$/', $code)) $code = (string)(int)$code; // ô số trong Excel
        $rows[] = ['code' => $code ?: null, 'name' => mb_substr($name, 0, 255), 'address' => $get('address') ?: null, 'distance' => $d === '' ? null : round((float)$d, 1)];
    }
    return [$rows, $errors];
}

if (is_post() && post('act') === 'import') {
    csrf_check();
    $file = $_FILES['file'] ?? null;
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!$file || !is_uploaded_file($file['tmp_name']) || !in_array($ext, ['xlsx', 'csv'], true)) {
        flash('danger', 'Chọn file .xlsx hoặc .csv. Nếu là file .xls cũ, hãy mở bằng Excel và lưu lại dạng .xlsx.');
        redirect('settings_facilities.php');
    }
    try {
        [$rows, $errors] = facility_parse($file['tmp_name'], $ext);
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
        redirect('settings_facilities.php');
    }
    // Khớp cơ sở đã có theo mã (nếu có), không có mã thì theo tên; có rồi thì cập nhật, chưa có thì thêm
    $pdo = db();
    $byCode = $pdo->prepare('SELECT id FROM facilities WHERE code = ? LIMIT 1');
    $byName = $pdo->prepare('SELECT id FROM facilities WHERE name = ? LIMIT 1');
    $upd = $pdo->prepare('UPDATE facilities SET code = COALESCE(?, code), name = ?, address = COALESCE(?, address), distance_km = COALESCE(?, distance_km), active = 1 WHERE id = ?');
    $ins = $pdo->prepare('INSERT INTO facilities (code, name, address, distance_km) VALUES (?,?,?,?)');
    $added = $updated = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $id = false;
            if ($r['code'] !== null) { $byCode->execute([$r['code']]); $id = $byCode->fetchColumn(); }
            if (!$id) { $byName->execute([$r['name']]); $id = $byName->fetchColumn(); }
            if ($id) {
                $upd->execute([$r['code'], $r['name'], $r['address'], $r['distance'], (int)$id]);
                $updated++;
            } else {
                $ins->execute([$r['code'], $r['name'], $r['address'], $r['distance']]);
                $added++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit('facility_import', $file['name'] . ": thêm $added, cập nhật $updated, lỗi " . count($errors));
    flash($rows ? 'success' : 'warning', "Đã nhập file {$file['name']}: thêm mới $added, cập nhật $updated cơ sở.");
    if ($errors) {
        flash('danger', count($errors) . ' dòng lỗi bị bỏ qua: ' . implode(' ', array_slice($errors, 0, 10)) . (count($errors) > 10 ? ' ...' : ''));
    }
    redirect('settings_facilities.php');
}

if (is_post()) {
    csrf_check();
    $act = post('act');
    $id = (int)post('id');
    if ($act === 'save') {
        $name = post('name');
        $dist = post('distance_km') === '' ? null : (float)str_replace(',', '.', post('distance_km'));
        if ($name === '' || ($dist !== null && ($dist < 0 || $dist > 5000))) {
            flash('danger', 'Tên cơ sở bắt buộc, khoảng cách 0–5000 km.');
        } else {
            $vals = [post('code') ?: null, $name, post('address') ?: null, $dist, post('is_home') === '1' ? 1 : 0];
            if ($id) {
                db()->prepare('UPDATE facilities SET code=?, name=?, address=?, distance_km=?, is_home=? WHERE id=?')->execute([...$vals, $id]);
            } else {
                db()->prepare('INSERT INTO facilities (code, name, address, distance_km, is_home) VALUES (?,?,?,?,?)')->execute($vals);
                $id = (int)db()->lastInsertId();
            }
            if ($vals[4]) {
                db()->prepare('UPDATE facilities SET is_home = (id = ?)')->execute([$id]);
            }
            audit('facility_save', $name);
            flash('success', 'Đã lưu cơ sở khám chữa bệnh.');
        }
    } elseif ($act === 'toggle' && $id) {
        db()->prepare('UPDATE facilities SET active = 1 - active WHERE id = ?')->execute([$id]);
    }
    redirect('settings_facilities.php');
}
$q = get('q');
$sql = 'SELECT * FROM facilities';
$args = [];
if ($q !== '') {
    $sql .= ' WHERE name LIKE ? OR code LIKE ?';
    $args = ['%' . $q . '%', '%' . $q . '%'];
}
$st = db()->prepare($sql . ' ORDER BY is_home DESC, active DESC, name');
$st->execute($args);
$rows = $st->fetchAll();
$edit = null;
if (get('edit')) {
    $s = db()->prepare('SELECT * FROM facilities WHERE id = ?');
    $s->execute([(int)get('edit')]);
    $edit = $s->fetch() ?: null;
}
render_header('Danh mục cơ sở khám chữa bệnh');
?>
<div class="row g-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-body pb-0"><form class="d-flex gap-2"><input name="q" value="<?= e($q) ?>" class="form-control" placeholder="Tìm theo tên hoặc mã"><button class="btn btn-light"><i class="bi bi-search"></i></button></form></div>
      <div class="table-responsive"><table class="table align-middle mb-0 mt-2">
        <thead><tr><th>Mã</th><th>Tên cơ sở</th><th>Địa chỉ</th><th class="num">Khoảng cách (km)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $r['active'] ? '' : 'text-muted text-decoration-line-through' ?>">
            <td><?= e($r['code']) ?></td><td><?= e($r['name']) ?> <?= $r['is_home'] ? '<span class="badge text-bg-primary">Bệnh viện mình</span>' : '' ?></td>
            <td class="small"><?= e($r['address']) ?></td><td class="num"><?= $r['distance_km'] !== null ? km_text($r['distance_km']) : '' ?></td>
            <td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="?edit=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light" title="Ngừng/dùng lại"><i class="bi bi-power"></i></button></form></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><?= $edit ? 'Sửa cơ sở' : 'Thêm cơ sở' ?></div>
      <div class="card-body">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="mb-2"><label class="form-label">Mã cơ sở KCB</label><input name="code" class="form-control" value="<?= e($edit['code'] ?? '') ?>"></div>
          <div class="mb-2"><label class="form-label">Tên cơ sở <span class="text-danger">*</span></label><input name="name" class="form-control" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="mb-2"><label class="form-label">Địa chỉ</label><input name="address" class="form-control" value="<?= e($edit['address'] ?? '') ?>"></div>
          <div class="mb-2"><label class="form-label">Khoảng cách từ bệnh viện (km)</label><input name="distance_km" type="number" step="0.1" min="0" class="form-control" value="<?= e($edit['distance_km'] ?? '') ?>">
            <div class="form-text">Tự điền vào phiếu chi khi chọn cơ sở này; người lập phiếu vẫn sửa được.</div></div>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_home" value="1" id="home" <?= !empty($edit['is_home']) ? 'checked' : '' ?>><label for="home" class="form-check-label">Đây là bệnh viện mình (nơi chuyển đến mặc định trên phiếu chi)</label></div>
          <button class="btn btn-primary">Lưu</button> <?php if ($edit): ?><a class="btn btn-light" href="settings_facilities.php">Hủy</a><?php endif; ?>
        </form>
      </div>
    </div>
    <div class="card mt-4">
      <div class="card-header"><i class="bi bi-file-earmark-excel text-success"></i> Nhập từ file Excel</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="import">
          <input type="file" name="file" class="form-control mb-2" accept=".xlsx,.csv" required>
          <div class="form-text mb-3">Cột: Mã cơ sở, Tên cơ sở, Địa chỉ, Khoảng cách (km); nhận diện theo tiêu đề, không cần đúng thứ tự. Cơ sở đã có (trùng mã, hoặc trùng tên nếu không có mã) sẽ được cập nhật, chưa có thì thêm mới. <a href="?template=1"><i class="bi bi-download"></i> Tải file mẫu</a></div>
          <button class="btn btn-success"><i class="bi bi-upload"></i> Nhập danh sách</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
