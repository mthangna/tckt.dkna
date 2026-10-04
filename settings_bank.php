<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/vietqr.php';
require_role('mod');

if (is_post()) {
    csrf_check();
    $act = post('act');
    $id = (int)post('id');
    if ($act === 'save') {
        $bin = post('bank_bin');
        $acc = preg_replace('/\s+/', '', post('account_no'));
        $name = transfer_text(post('account_name'));
        $banks = VietQR::banks();
        if (!isset($banks[$bin]) || !preg_match('/^[0-9A-Za-z]{4,19}$/', $acc) || $name === '') {
            flash('danger', 'Thông tin không hợp lệ: chọn ngân hàng, số tài khoản 4–19 ký tự chữ/số, tên chủ tài khoản bắt buộc.');
        } else {
            if ($id) {
                db()->prepare('UPDATE bank_accounts SET bank_bin=?, bank_name=?, account_no=?, account_name=? WHERE id=?')->execute([$bin, $banks[$bin], $acc, $name, $id]);
            } else {
                $first = (int)db()->query('SELECT COUNT(*) FROM bank_accounts')->fetchColumn() === 0;
                db()->prepare('INSERT INTO bank_accounts (bank_bin, bank_name, account_no, account_name, is_default) VALUES (?,?,?,?,?)')
                    ->execute([$bin, $banks[$bin], $acc, $name, $first ? 1 : 0]);
            }
            audit('bank_account_save', "$bin $acc");
            flash('success', 'Đã lưu tài khoản nhận tiền.');
        }
    } elseif ($act === 'default' && $id) {
        db()->prepare('UPDATE bank_accounts SET is_default = (id = ?)')->execute([$id]);
        flash('success', 'Đã đặt làm tài khoản mặc định.');
    } elseif ($act === 'toggle' && $id) {
        db()->prepare('UPDATE bank_accounts SET active = 1 - active WHERE id = ?')->execute([$id]);
        audit('bank_account_toggle', (string)$id);
    }
    redirect('settings_bank.php');
}
$rows = db()->query('SELECT * FROM bank_accounts ORDER BY is_default DESC, id')->fetchAll();
$edit = null;
foreach ($rows as $r) {
    if ((int)get('edit') === (int)$r['id']) $edit = $r;
}
render_header('Tài khoản nhận tiền QR');
?>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card">
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Ngân hàng</th><th>Số tài khoản</th><th>Chủ tài khoản</th><th>Trạng thái</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $r['active'] ? '' : 'text-muted' ?>">
            <td><?= e($r['bank_name']) ?> <span class="small text-muted">(<?= e($r['bank_bin']) ?>)</span></td>
            <td class="fw-semibold"><?= e($r['account_no']) ?></td><td><?= e($r['account_name']) ?></td>
            <td><?= $r['is_default'] ? '<span class="badge text-bg-primary">Mặc định</span> ' : '' ?><?= $r['active'] ? '<span class="badge text-bg-success">Đang dùng</span>' : '<span class="badge text-bg-secondary">Ngừng</span>' ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-light" href="?edit=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i></a>
              <?php if (!$r['is_default']): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="default"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light" title="Đặt mặc định"><i class="bi bi-star"></i></button></form>
              <?php endif; ?>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light" title="Bật/tắt"><i class="bi bi-power"></i></button></form>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-3">Chưa có tài khoản</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><?= $edit ? 'Sửa tài khoản' : 'Thêm tài khoản' ?></div>
      <div class="card-body">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="mb-3"><label class="form-label">Ngân hàng</label>
            <select name="bank_bin" class="form-select">
              <?php foreach (VietQR::banks() as $bin => $name): ?><option value="<?= $bin ?>" <?= ($edit['bank_bin'] ?? '970418') === (string)$bin ? 'selected' : '' ?>><?= e($name) ?> (<?= $bin ?>)</option><?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label">Số tài khoản</label><input name="account_no" class="form-control" value="<?= e($edit['account_no'] ?? '') ?>" required></div>
          <div class="mb-3"><label class="form-label">Tên chủ tài khoản</label><input name="account_name" class="form-control text-uppercase" value="<?= e($edit['account_name'] ?? '') ?>" required>
            <div class="form-text">Viết in hoa không dấu đúng như trên ngân hàng.</div></div>
          <button class="btn btn-primary">Lưu</button>
          <?php if ($edit): ?><a href="settings_bank.php" class="btn btn-light">Hủy</a><?php endif; ?>
        </form>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
