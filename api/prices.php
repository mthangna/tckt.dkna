<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require_login();

$cat = get('cat', 'bhyt');
if (!in_array($cat, price_categories(), true)) {
    json_out(['ok' => false, 'error' => 'Nhóm giá không hợp lệ.'], 400);
}

function item_out(array $r): array
{
    $today = date('Y-m-d');
    $state = $r['effective_from'] > $today ? 'future' : (($r['effective_to'] !== null && $r['effective_to'] < $today) ? 'expired' : 'active');
    return [
        'id'             => (int)$r['id'],
        'equiv_code'     => $r['equiv_code'],
        'tech_code'      => $r['tech_code'],
        'name'           => $r['name'],
        'unit'           => $r['unit'],
        'price'          => (float)$r['price'],
        'decision_name'  => $r['decision_name'],
        'decision_date'  => vn_date($r['decision_date']),
        'effective_from' => vn_date($r['effective_from']),
        'effective_to'   => vn_date($r['effective_to']),
        'note'           => $r['note'],
        'state'          => $state,
    ];
}

// Lịch sử giá của một mục (cùng mã)
if (get('history') !== '') {
    $st = db()->prepare('SELECT * FROM price_items WHERE id = ?');
    $st->execute([(int)get('history')]);
    $it = $st->fetch();
    if (!$it) {
        json_out(['ok' => false, 'error' => 'Không tìm thấy.'], 404);
    }
    if ($it['equiv_code']) {
        $st = db()->prepare('SELECT * FROM price_items WHERE category = ? AND equiv_code = ? ORDER BY effective_from DESC');
        $st->execute([$it['category'], $it['equiv_code']]);
    } elseif ($it['tech_code']) {
        $st = db()->prepare('SELECT * FROM price_items WHERE category = ? AND tech_code = ? ORDER BY effective_from DESC');
        $st->execute([$it['category'], $it['tech_code']]);
    } else {
        $st = db()->prepare('SELECT * FROM price_items WHERE category = ? AND name_search = ? ORDER BY effective_from DESC');
        $st->execute([$it['category'], $it['name_search']]);
    }
    json_out(['ok' => true, 'item' => item_out($it), 'history' => array_map('item_out', $st->fetchAll())]);
}

$per = (int)get('per', '20');
if (!in_array($per, [20, 50, 100], true)) {
    $per = 20;
}
$page = max(1, (int)get('page', '1'));
$q = get('q');
$all = get('all') === '1';

$where = ['category = ?'];
$args = [$cat];
if (!$all) {
    $where[] = 'effective_from <= CURDATE() AND (effective_to IS NULL OR effective_to >= CURDATE())';
}
if ($q !== '') {
    $like = '%' . addcslashes(vn_unaccent($q), '%_\\') . '%';
    $likeCode = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(name_search LIKE ? OR equiv_code LIKE ? OR tech_code LIKE ?)';
    array_push($args, $like, $likeCode, $likeCode);
}
$w = implode(' AND ', $where);
$st = db()->prepare("SELECT COUNT(*) FROM price_items WHERE $w");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$offset = ($page - 1) * $per;
$codeCol = $cat === 'khac' ? 'equiv_code' : 'tech_code';
$st = db()->prepare("SELECT * FROM price_items WHERE $w ORDER BY ($codeCol IS NULL), $codeCol, name, effective_from DESC LIMIT $per OFFSET $offset");
$st->execute($args);

json_out([
    'ok'    => true,
    'total' => $total,
    'page'  => $page,
    'pages' => $pages,
    'per'   => $per,
    'from'  => $total ? $offset + 1 : 0,
    'rows'  => array_map('item_out', $st->fetchAll()),
]);
