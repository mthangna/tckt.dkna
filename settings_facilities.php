<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_role('mod');

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
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_home" value="1" id="home" <?= !empty($edit['is_home']) ? 'checked' : '' ?>><label for="home" class="form-check-label">Đây là bệnh viện mình (cơ sở chuyển đi mặc định)</label></div>
          <button class="btn btn-primary">Lưu</button> <?php if ($edit): ?><a class="btn btn-light" href="settings_facilities.php">Hủy</a><?php endif; ?>
        </form>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
