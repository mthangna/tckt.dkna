<?php
declare(strict_types=1);

/** Đọc số tiền thành chữ tiếng Việt, ví dụ 1.250.000 → "Một triệu hai trăm năm mươi nghìn đồng" */
function vn_number_words(int $n): string
{
    if ($n === 0) {
        return 'Không đồng';
    }
    $negative = $n < 0;
    $n = abs($n);
    $units = ['', ' nghìn', ' triệu', ' tỷ', ' nghìn tỷ', ' triệu tỷ'];
    $groups = [];
    while ($n > 0) {
        $groups[] = $n % 1000;
        $n = intdiv($n, 1000);
    }
    $parts = [];
    $count = count($groups);
    for ($i = $count - 1; $i >= 0; $i--) {
        $g = $groups[$i];
        if ($g === 0) {
            continue;
        }
        $full = $i < $count - 1; // nhóm không phải nhóm đầu tiên → đọc đủ "không trăm"
        $parts[] = vn_read_triple($g, $full) . $units[$i];
    }
    $s = trim(implode(' ', $parts));
    $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    return ($negative ? 'Âm ' . mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1) : $s) . ' đồng';
}

function vn_read_triple(int $n, bool $full): string
{
    $digits = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
    $tram = intdiv($n, 100);
    $chuc = intdiv($n % 100, 10);
    $dv = $n % 10;
    $out = [];
    if ($tram > 0 || $full) {
        $out[] = $digits[$tram] . ' trăm';
    }
    if ($chuc === 0) {
        if ($dv > 0 && ($tram > 0 || $full)) {
            $out[] = 'linh';
        }
    } elseif ($chuc === 1) {
        $out[] = 'mười';
    } else {
        $out[] = $digits[$chuc] . ' mươi';
    }
    if ($dv > 0) {
        if ($dv === 1 && $chuc >= 2) {
            $out[] = 'mốt';
        } elseif ($dv === 5 && $chuc >= 1) {
            $out[] = 'lăm';
        } elseif ($dv === 4 && $chuc >= 2) {
            $out[] = 'tư';
        } else {
            $out[] = $digits[$dv];
        }
    }
    return implode(' ', $out);
}
