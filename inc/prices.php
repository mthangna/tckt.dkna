<?php
declare(strict_types=1);

require_once __DIR__ . '/xlsx.php';

/**
 * Nhập danh mục giá từ Excel.
 * Cột được nhận diện theo tiêu đề (không phân biệt hoa thường, có dấu hay không dấu),
 * nên file có thể sắp xếp cột theo thứ tự bất kỳ.
 */
final class PriceImport
{
    public const COLUMNS = [
        'equiv_code'     => ['ma tuong duong', 'ma td'],
        'tech_code'      => ['ma ky thuat', 'ma dvkt', 'ma kt'],
        'name'           => ['ten dich vu', 'ten dvkt', 'ten ky thuat', 'ten'],
        'unit'           => ['don vi tinh', 'don vi', 'dvt'],
        'price'          => ['don gia', 'muc gia', 'gia'],
        'decision_name'  => ['quyet dinh', 'so qd', 'can cu'],
        'decision_date'  => ['ngay ban hanh', 'ngay qd', 'ngay ky'],
        'effective_from' => ['ngay ap dung', 'ngay hieu luc', 'ap dung tu'],
        'note'           => ['ghi chu'],
    ];

    /** Nhóm "dịch vụ khác": chỉ có Mã dịch vụ (lưu vào cột equiv_code), không có mã kỹ thuật */
    public static function columnsFor(string $category): array
    {
        $cols = self::COLUMNS;
        if ($category === 'khac') {
            $cols['equiv_code'] = ['ma dich vu', 'ma dv'];
            unset($cols['tech_code']);
        }
        return $cols;
    }

    /** Đọc file, trả về ['rows' => [...], 'errors' => [...], 'columns' => [...]] */
    public static function parse(string $path, string $ext, array $defaults, string $category = 'bhyt'): array
    {
        $raw = $ext === 'csv' ? Xlsx::readCsv($path) : Xlsx::readRows($path);
        [$headerIdx, $map] = self::detectHeader($raw, self::columnsFor($category));
        if ($headerIdx === null) {
            throw new RuntimeException('Không tìm thấy dòng tiêu đề. File cần có ít nhất cột "Tên dịch vụ" và "Đơn giá".');
        }
        $rows = [];
        $errors = [];
        foreach ($raw as $i => $line) {
            if ($i <= $headerIdx) {
                continue;
            }
            $get = fn(string $k) => isset($map[$k]) ? trim((string)($line[$map[$k]] ?? '')) : '';
            $name = $get('name');
            $priceRaw = $get('price');
            if ($name === '' && $priceRaw === '') {
                continue; // dòng trống / dòng nhóm
            }
            $excelRow = $i + 1;
            $price = self::parseMoney($priceRaw);
            if ($name === '') {
                $errors[] = "Dòng $excelRow: thiếu tên dịch vụ.";
                continue;
            }
            if ($price === null) {
                // Dòng tiêu đề nhóm (ví dụ "I. KHÁM BỆNH") không có giá → bỏ qua, không báo lỗi
                if ($priceRaw === '') {
                    continue;
                }
                $errors[] = "Dòng $excelRow: đơn giá không hợp lệ ($priceRaw).";
                continue;
            }
            $from = self::parseDate($get('effective_from')) ?? $defaults['effective_from'];
            if (!$from) {
                $errors[] = "Dòng $excelRow: thiếu ngày áp dụng (điền trong file hoặc ở ô mặc định).";
                continue;
            }
            $rows[] = [
                'row'            => $excelRow,
                'equiv_code'     => $get('equiv_code') ?: null,
                'tech_code'      => $get('tech_code') ?: null,
                'name'           => $name,
                'unit'           => $get('unit') ?: null,
                'price'          => $price,
                'decision_name'  => $get('decision_name') ?: ($defaults['decision_name'] ?: null),
                'decision_date'  => self::parseDate($get('decision_date')) ?? ($defaults['decision_date'] ?: null),
                'effective_from' => $from,
                'note'           => $get('note') ?: null,
            ];
        }
        $found = array_keys($map);
        return ['rows' => $rows, 'errors' => $errors, 'columns' => $found];
    }

    /** So sánh với dữ liệu đang hiệu lực: trả về các hành động new/update/same/skip */
    public static function plan(string $category, array $rows): array
    {
        $existing = [];
        $st = db()->prepare('SELECT * FROM price_items WHERE category = ? AND effective_to IS NULL');
        $st->execute([$category]);
        foreach ($st as $it) {
            $existing[self::key($it)] = $it;
        }
        $plan = [];
        $seen = [];
        foreach ($rows as $r) {
            $k = self::key($r);
            if (isset($seen[$k])) {
                $plan[] = ['action' => 'skip', 'row' => $r, 'reason' => 'Trùng mã với dòng ' . $seen[$k]];
                continue;
            }
            $seen[$k] = $r['row'];
            $old = $existing[$k] ?? null;
            if (!$old) {
                $plan[] = ['action' => 'new', 'row' => $r];
            } elseif ((float)$old['price'] === (float)$r['price'] && $old['effective_from'] === $r['effective_from']
                && (string)$old['name'] === $r['name'] && (string)$old['decision_name'] === (string)$r['decision_name']) {
                $plan[] = ['action' => 'same', 'row' => $r, 'old_id' => (int)$old['id']];
            } elseif ($r['effective_from'] === $old['effective_from']) {
                $plan[] = ['action' => 'fix', 'row' => $r, 'old_id' => (int)$old['id'], 'old_price' => $old['price']];
            } elseif ($r['effective_from'] > $old['effective_from']) {
                $plan[] = ['action' => 'update', 'row' => $r, 'old_id' => (int)$old['id'], 'old_price' => $old['price']];
            } else {
                $plan[] = ['action' => 'skip', 'row' => $r, 'reason' => 'Ngày áp dụng ' . vn_date($r['effective_from']) . ' sớm hơn giá đang hiệu lực (' . vn_date($old['effective_from']) . ')'];
            }
        }
        $missing = [];
        foreach ($existing as $k => $it) {
            if (!isset($seen[$k])) {
                $missing[] = $it;
            }
        }
        return ['items' => $plan, 'missing' => $missing];
    }

    /** Ghi vào CSDL trong 1 giao dịch */
    public static function apply(string $category, array $plan, bool $closeMissing, string $fileName, int $userId): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO price_batches (category, file_name, row_count, uploaded_by) VALUES (?,?,?,?)')
                ->execute([$category, $fileName, 0, $userId]);
            $batchId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO price_items (category, equiv_code, tech_code, name, name_search, unit, price, decision_name, decision_date, effective_from, note, batch_id)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $close = $pdo->prepare('UPDATE price_items SET effective_to = ? WHERE id = ? AND effective_to IS NULL');
            $fix = $pdo->prepare('UPDATE price_items SET equiv_code=?, tech_code=?, name=?, name_search=?, unit=?, price=?, decision_name=?, decision_date=?, note=?, batch_id=? WHERE id=?');
            $count = ['new' => 0, 'update' => 0, 'fix' => 0, 'closed' => 0];
            $minFrom = null;
            foreach ($plan['items'] as $p) {
                $r = $p['row'];
                $minFrom = $minFrom === null ? $r['effective_from'] : min($minFrom, $r['effective_from']);
                $search = vn_unaccent($r['name']);
                if ($p['action'] === 'new' || $p['action'] === 'update') {
                    if ($p['action'] === 'update') {
                        $close->execute([date('Y-m-d', strtotime($r['effective_from'] . ' -1 day')), $p['old_id']]);
                    }
                    $ins->execute([$category, $r['equiv_code'], $r['tech_code'], $r['name'], $search, $r['unit'], $r['price'],
                        $r['decision_name'], $r['decision_date'], $r['effective_from'], $r['note'], $batchId]);
                    $count[$p['action']]++;
                } elseif ($p['action'] === 'fix') {
                    $fix->execute([$r['equiv_code'], $r['tech_code'], $r['name'], $search, $r['unit'], $r['price'],
                        $r['decision_name'], $r['decision_date'], $r['note'], $batchId, $p['old_id']]);
                    $count['fix']++;
                }
            }
            if ($closeMissing && $plan['missing'] && $minFrom) {
                $endDate = date('Y-m-d', strtotime($minFrom . ' -1 day'));
                foreach ($plan['missing'] as $it) {
                    // Không đặt ngày hết hiệu lực trước ngày bắt đầu
                    $close->execute([max($endDate, $it['effective_from']), $it['id']]);
                    $count['closed']++;
                }
            }
            $pdo->prepare('UPDATE price_batches SET row_count = ?, note = ? WHERE id = ?')
                ->execute([$count['new'] + $count['update'] + $count['fix'], json_encode($count), $batchId]);
            $pdo->commit();
            return $count;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function key(array $r): string
    {
        if (!empty($r['equiv_code'])) {
            return 'E:' . mb_strtoupper(trim((string)$r['equiv_code']));
        }
        if (!empty($r['tech_code'])) {
            return 'T:' . mb_strtoupper(trim((string)$r['tech_code']));
        }
        return 'N:' . vn_unaccent((string)$r['name']);
    }

    private static function detectHeader(array $raw, array $columns): array
    {
        foreach ($raw as $i => $line) {
            if ($i > 20) {
                break;
            }
            $map = [];
            foreach ($line as $col => $cell) {
                $h = vn_unaccent((string)$cell);
                if ($h === '') {
                    continue;
                }
                // Chọn trường có từ khoá khớp dài nhất (vd "Quyết định ban hành giá" → quyết định, không phải giá)
                $best = null;
                $bestLen = 0;
                foreach ($columns as $field => $keys) {
                    foreach ($keys as $kw) {
                        if (strlen($kw) > $bestLen && preg_match('/(^|[^a-z0-9])' . preg_quote($kw, '/') . '([^a-z0-9]|$)/', $h)) {
                            $best = $field;
                            $bestLen = strlen($kw);
                        }
                    }
                }
                if ($best !== null && !isset($map[$best])) {
                    $map[$best] = $col;
                }
            }
            if (isset($map['name'], $map['price'])) {
                return [$i, $map];
            }
        }
        return [null, []];
    }

    public static function parseMoney(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        // 1.234.567 hoặc 1,234,567 hoặc 1 234 567 (dấu phân cách hàng nghìn)
        if (preg_match('/^\d{1,3}([.,\s]\d{3})+$/u', $s)) {
            return (float)preg_replace('/[.,\s]/u', '', $s);
        }
        if (is_numeric($s)) {
            return round((float)$s);
        }
        $digits = preg_replace('/[.,\s]/u', '', $s);
        return ctype_digit($digits) ? (float)$digits : null;
    }

    public static function parseDate(string $s): ?string
    {
        if ($s === '') {
            return null;
        }
        return Xlsx::serialToDate($s) ?? parse_date($s);
    }
}
