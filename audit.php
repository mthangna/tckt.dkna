<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');
$q = get('q');
$args = [];
$sql = 'SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id';
if ($q !== '') {
    $sql .= ' WHERE a.action LIKE ? OR a.detail LIKE ? OR u.username LIKE ?';
    $args = array_fill(0, 3, '%' . $q . '%');
}
$st = db()->prepare($sql . ' ORDER BY a.id DESC LIMIT 500');
$st->execute($args);
render_header('Nhật ký hệ thống');
?>
<div class="card">
  <div class="card-body pb-0"><form class="d-flex gap-2" style="max-width:420px"><input name="q" class="form-control" value="<?= e($q) ?>" placeholder="Lọc theo thao tác, nội dung, người dùng"><button class="btn btn-light"><i class="bi bi-search"></i></button></form></div>
  <div class="table-responsive mt-2"><table class="table table-sm mb-0">
    <thead><tr><th>Thời gian</th><th>Người dùng</th><th>Thao tác</th><th>Chi tiết</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($st as $r): ?>
      <tr><td class="text-nowrap"><?= vn_date($r['created_at'], true) ?></td><td><?= e($r['username'] ?? '—') ?></td><td><code><?= e($r['action']) ?></code></td><td class="small"><?= e($r['detail']) ?></td><td class="small"><?= e($r['ip']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php render_footer();
