<?php
declare(strict_types=1);

/**
 * Đọc / ghi file Excel .xlsx gọn nhẹ (chỉ cần ZipArchive + SimpleXML có sẵn trong PHP),
 * không phụ thuộc thư viện ngoài để dễ cài trên máy chủ nội bộ.
 */
final class Xlsx
{
    /** Đọc sheet đầu tiên thành mảng các dòng, khoá là số thứ tự dòng thật trong Excel (bắt đầu từ 0) */
    public static function readRows(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Máy chủ thiếu tiện ích PHP zip.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Không mở được file Excel (cần định dạng .xlsx).');
        }
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $ss = self::xml($ssXml);
            foreach ($ss->si as $si) {
                $shared[] = self::richText($si);
            }
        }
        $sheetPath = self::firstSheetPath($zip);
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('File Excel không có sheet dữ liệu.');
        }
        $sheet = self::xml($sheetXml);
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $rowIdx = isset($row['r']) ? (int)$row['r'] - 1 : count($rows);
            $r = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = $ref !== '' ? self::colIndex($ref) : count($r);
                $type = (string)$c['t'];
                $v = isset($c->v) ? (string)$c->v : null;
                if ($type === 's') {
                    $val = $shared[(int)$v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = self::richText($c->is);
                } elseif ($type === 'b') {
                    $val = $v === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $val = $v ?? '';
                }
                $r[$col] = trim((string)$val);
            }
            if ($r) {
                $max = max(array_keys($r));
                $filled = [];
                for ($i = 0; $i <= $max; $i++) {
                    $filled[] = $r[$i] ?? '';
                }
                $rows[$rowIdx] = $filled;
            }
        }
        return $rows;
    }

    /** Đọc CSV (UTF-8, dấu phẩy hoặc chấm phẩy) */
    public static function readCsv(string $path): array
    {
        $content = file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $firstLine = strtok($content, "\n");
        $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            $rows[] = array_map('trim', $r);
        }
        fclose($fh);
        return $rows;
    }

    /** Chuyển số ngày kiểu Excel (vd 45658) thành yyyy-mm-dd */
    public static function serialToDate($v): ?string
    {
        if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {
            return gmdate('Y-m-d', (int)round(((float)$v - 25569) * 86400));
        }
        return null;
    }

    /**
     * Xuất file .xlsx và gửi về trình duyệt.
     * $rows: mảng các dòng; giá trị số (int/float) ghi dạng số, còn lại ghi dạng chữ.
     */
    public static function download(string $filename, array $header, array $rows, string $title = ''): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Style 0: thường, 1: đậm, 2: số có phân cách nghìn, 3: tiêu đề lớn
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3"><font><sz val="11"/><name val="Times New Roman"/></font><font><b/><sz val="11"/><name val="Times New Roman"/></font><font><b/><sz val="14"/><name val="Times New Roman"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="4"><xf fontId="0"/><xf fontId="1" applyFont="1"/><xf fontId="0" numFmtId="3" applyNumberFormat="1"/><xf fontId="2" applyFont="1"/></cellXfs>'
            . '</styleSheet>');

        $xmlRows = '';
        $r = 1;
        if ($title !== '') {
            $xmlRows .= '<row r="1">' . self::cell(0, 1, $title, 3) . '</row>';
            $r = 3;
        }
        $line = '';
        foreach ($header as $i => $h) {
            $line .= self::cell($i, $r, (string)$h, 1);
        }
        $xmlRows .= '<row r="' . $r . '">' . $line . '</row>';
        foreach ($rows as $row) {
            $r++;
            $line = '';
            foreach (array_values($row) as $i => $v) {
                $isNum = is_int($v) || is_float($v);
                $line .= self::cell($i, $r, $v, $isNum ? 2 : (is_array($v) ? 1 : 0));
            }
            $xmlRows .= '<row r="' . $r . '">' . $line . '</row>';
        }
        $cols = '<cols>';
        foreach ($header as $i => $h) {
            $w = max(10, min(50, mb_strlen((string)$h) + 6));
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $cols . '<sheetData>' . $xmlRows . '</sheetData></worksheet>');
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    private static function cell(int $col, int $row, $value, int $style): string
    {
        if (is_array($value)) { // ['text'] → in đậm
            $value = (string)reset($value);
        }
        $ref = self::colName($col) . $row;
        if (is_int($value) || is_float($value)) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
        }
        $text = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
    }

    private static function xml(string $s): SimpleXMLElement
    {
        $x = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        if ($x === false) {
            throw new RuntimeException('File Excel bị lỗi cấu trúc.');
        }
        return $x;
    }

    private static function richText(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string)$node->t;
        }
        $s = '';
        foreach ($node->r ?? [] as $run) {
            $s .= (string)$run->t;
        }
        return $s;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $wbX = self::xml($wb);
            $relX = self::xml($rels);
            $sheet = $wbX->sheets->sheet[0] ?? null;
            if ($sheet) {
                $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($relX->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $t = ltrim((string)$rel['Target'], '/');
                        return str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                    }
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $n = 0;
        foreach (str_split($m[1] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    public static function colName(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    }
}
