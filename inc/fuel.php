<?php
declare(strict_types=1);

/** Trang chủ Petrolimex – nguồn mặc định */
const FUEL_PLX_HOME = 'https://www.petrolimex.com.vn/index.html';

/**
 * API công khai mà trang chủ Petrolimex dùng để nạp bảng giá bán lẻ (JS: __vieapps.prices.fetch).
 * Tham số x-request là JSON (bộ lọc kho dữ liệu "giá bán lẻ") mã hoá base64url.
 */
const FUEL_PLX_API = 'https://portals.petrolimex.com.vn/~apis/portals/cms.item/search';
const FUEL_PLX_FILTER = [
    'SystemID'           => '6783dc1271ff449e95b74a9520964169',
    'RepositoryID'       => 'a95451e23b474fe5886bfb7cf843f53c',
    'RepositoryEntityID' => '3801378fe1e045b1afa10de7c5776124',
];

/**
 * Lấy giá xăng tham khảo.
 * - Nguồn là trang Petrolimex (petrolimex.com.vn): gọi thẳng API JSON của Petrolimex, lấy Vùng 1/Vùng 2.
 * - Nguồn khác: tải trang HTML, tìm dòng bảng có chứa từ khoá (vd "E5 RON 92"),
 *   lấy các con số dạng giá (10.000–99.999) theo thứ tự cột: cột đầu là Vùng 1, cột sau là Vùng 2.
 */
function fuel_fetch_reference(string $url, string $keyword): array
{
    $url = trim($url) !== '' ? trim($url) : FUEL_PLX_HOME;
    $keyword = trim($keyword) !== '' ? trim($keyword) : 'E5 RON 92';
    if (fuel_is_petrolimex($url)) {
        $r = fuel_fetch_petrolimex($keyword);
    } else {
        $r = fuel_parse_html(http_get($url), $keyword);
    }
    return $r + ['source' => $url, 'fetched_at' => date('Y-m-d H:i:s')];
}

function fuel_is_petrolimex(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return $host === 'petrolimex.com.vn' || str_ends_with($host, '.petrolimex.com.vn');
}

function fuel_fetch_petrolimex(string $keyword): array
{
    $and = [];
    foreach (FUEL_PLX_FILTER as $field => $value) {
        $and[] = [$field => ['Equals' => $value]];
    }
    $and[] = ['Status' => ['Equals' => 'Published']];
    $request = [
        'FilterBy'   => ['And' => $and],
        'SortBy'     => ['LastModified' => 'Descending'],
        'Pagination' => ['TotalRecords' => -1, 'TotalPages' => 0, 'PageSize' => 0, 'PageNumber' => 0],
    ];
    $xr = rtrim(strtr(base64_encode(json_encode($request, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    $body = http_get(FUEL_PLX_API . '?x-request=' . $xr, 15, [
        'Accept: application/json',
        'Referer: https://www.petrolimex.com.vn/',
    ]);
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['Objects']) || !is_array($data['Objects'])) {
        return ['ok' => false, 'error' => 'Dữ liệu giá trả về từ Petrolimex không đúng định dạng (không có danh sách sản phẩm). Petrolimex có thể đã đổi cách công bố giá trên trang chủ; cần cập nhật lại tính năng hoặc nhập giá thủ công.'];
    }
    $items = [];
    foreach ($data['Objects'] as $o) {
        if (!is_array($o) || !isset($o['Title'])) {
            continue;
        }
        $items[] = [
            'title'    => trim((string)$o['Title']),
            'zone1'    => fuel_price_int($o['Zone1Price'] ?? null),
            'zone2'    => fuel_price_int($o['Zone2Price'] ?? null),
            'order'    => (int)($o['DIsplayOrder'] ?? $o['DisplayOrder'] ?? $o['OrderIndex'] ?? 0),
            'modified' => (string)($o['LastModified'] ?? ''),
        ];
    }
    if (!$items) {
        return ['ok' => false, 'error' => 'Petrolimex không trả về sản phẩm nào trong bảng giá bán lẻ. Petrolimex có thể đã đổi cấu trúc dữ liệu; hãy thử lại sau hoặc nhập giá thủ công.'];
    }
    usort($items, fn($a, $b) => $a['order'] <=> $b['order']);

    $item = fuel_match_item($items, $keyword);
    if ($item === null) {
        $names = implode('; ', array_column($items, 'title'));
        return ['ok' => false, 'error' => 'Không tìm thấy mặt hàng khớp từ khoá "' . $keyword . '" trong bảng giá Petrolimex. Các mặt hàng hiện có: ' . $names . '. Hãy sửa từ khoá ở mục Giá xăng.'];
    }
    if ($item['zone1'] === null) {
        return ['ok' => false, 'error' => 'Petrolimex có mặt hàng "' . $item['title'] . '" nhưng không có giá Vùng 1 hợp lệ. Petrolimex có thể đã đổi cấu trúc dữ liệu; hãy nhập giá thủ công.'];
    }
    $r = ['ok' => true, 'label' => $item['title'], 'zone1' => $item['zone1'], 'zone2' => $item['zone2']];
    if ($item['modified'] !== '' && ($ts = strtotime($item['modified'])) !== false) {
        $r['updated_at'] = date('Y-m-d H:i:s', $ts); // giờ Petrolimex cập nhật giá, theo múi giờ hệ thống
    }
    return $r;
}

/**
 * Tìm mặt hàng theo từ khoá (không phân biệt dấu/hoa thường).
 * Petrolimex ghi mức chuẩn dạng "Mức 2" thay cho "-II": thử đổi hậu tố La Mã sang "muc N",
 * nếu vẫn không có thì bỏ hẳn hậu tố mức rồi tìm lại.
 */
function fuel_match_item(array $items, string $keyword): ?array
{
    $kws = [vn_unaccent($keyword)];
    $roman = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6];
    if (preg_match('/^(.*?)\s*-\s*(i{1,3}|iv|vi?)$/', $kws[0], $m)) {
        $kws[] = trim($m[1]) . ' muc ' . $roman[$m[2]];
    }
    $base = trim(preg_replace('/(\s*-\s*[ivx]+|\s+muc\s*\d+)\s*$/', '', $kws[0]));
    if ($base !== '' && !in_array($base, $kws, true)) {
        $kws[] = $base;
    }
    foreach ($kws as $kw) {
        foreach ($items as $it) {
            if (str_contains(vn_unaccent($it['title']), $kw)) {
                return $it;
            }
        }
    }
    return null;
}

function fuel_price_int($v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    $n = is_numeric($v) ? (int)round((float)$v) : (int)preg_replace('/[^\d]/', '', (string)$v);
    return ($n >= 10000 && $n <= 99999) ? $n : null;
}

function fuel_parse_html(string $html, string $keyword): array
{
    $kw = vn_unaccent($keyword);
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    foreach ($doc->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $td) {
            if ($td instanceof DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent));
            }
        }
        if (!$cells || !str_contains(vn_unaccent($cells[0]), $kw)) {
            continue;
        }
        $prices = [];
        foreach (array_slice($cells, 1) as $c) {
            $digits = preg_replace('/[^\d]/', '', $c);
            if ($digits !== '' && (int)$digits >= 10000 && (int)$digits <= 99999) {
                $prices[] = (int)$digits;
            }
        }
        if ($prices) {
            return ['ok' => true, 'label' => $cells[0], 'zone1' => $prices[0], 'zone2' => $prices[1] ?? null];
        }
    }
    return ['ok' => false, 'error' => 'Không tìm thấy dòng "' . $keyword . '" có giá trong trang nguồn. Trang có thể đã đổi cấu trúc; kiểm tra lại địa chỉ nguồn và từ khoá ở mục Giá xăng.'];
}

function http_get(string $url, int $timeout = 15, array $headers = []): string
{
    if (!preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('Địa chỉ nguồn không hợp lệ.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) TCKT-Tool/1.0',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',
    ]);
    if ($proxy = config('http_proxy')) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        throw new RuntimeException('Không tải được trang nguồn' . ($err ? ": $err" : " (HTTP $code)") . '. Máy chủ có thể không ra được Internet; khai báo proxy trong config.php nếu cần.');
    }
    return (string)$body;
}

/** Giá xăng đang áp dụng (bản ghi mới nhất có hiệu lực tới thời điểm hiện tại) */
function fuel_current(): ?array
{
    $r = db()->query('SELECT * FROM fuel_prices WHERE effective_at <= NOW() ORDER BY effective_at DESC, id DESC LIMIT 1')->fetch();
    return $r ?: null;
}
