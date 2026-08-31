<?php
/**
 * Minimal, dependency-free OOXML (.xlsx) writer.
 *
 * There is no PHP equivalent of openpyxl bundled with WordPress, and pulling
 * in a Composer library (PhpSpreadsheet) is unreasonable for a single-purpose
 * plugin, so this hand-writes the small subset of the spec needed: shared
 * strings, a handful of cell styles (header/body/number formats), a frozen
 * header row, an autofilter, and a right-to-left sheet view. Correctness is
 * checked in tests/test-xlsx.php by round-tripping through both openpyxl and
 * LibreOffice, not just "PHP didn't throw".
 */

if (!defined('ABSPATH') && !defined('GC_STANDALONE_TEST')) {
    exit;
}

final class GC_Xlsx {

    /** @var string[] */
    private $shared_strings = array();
    /** @var array<string,int> */
    private $string_index = array();
    /** @var array{name:string, rows:array, widths:int[], rtl:bool}[] */
    private $sheets = array();

    private function string_id($value) {
        $value = (string) $value;
        if (isset($this->string_index[$value])) {
            return $this->string_index[$value];
        }
        $id = count($this->shared_strings);
        $this->shared_strings[] = $value;
        $this->string_index[$value] = $id;
        return $id;
    }

    /**
     * @param string $name       sheet title (will be sanitised/truncated/uniquified)
     * @param array  $rows       list of rows; each row is a list of cells:
     *                           ['v' => mixed, 'style' => 'header'|'body'|'body0'|'body2'|'body4']
     * @param int[]  $widths     column widths (characters), one per column
     */
    public function add_sheet($name, array $rows, array $widths = array()) {
        $this->sheets[] = array('name' => $this->sanitise_sheet_name($name), 'rows' => $rows, 'widths' => $widths);
    }

    private function sanitise_sheet_name($name) {
        $cleaned = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name));
        if ($cleaned === '') {
            $cleaned = 'Sheet';
        }
        $cleaned = mb_substr($cleaned, 0, 31);

        static $used = array();
        $candidate = $cleaned;
        $i = 2;
        while (in_array($candidate, $used, true)) {
            $suffix = " ({$i})";
            $candidate = mb_substr($cleaned, 0, 31 - mb_strlen($suffix)) . $suffix;
            $i++;
        }
        $used[] = $candidate;
        return $candidate;
    }

    // -- style indices into cellXfs (see styles_xml()) ----------------------
    const STYLE_HEADER = 1;
    const STYLE_BODY0 = 2;
    const STYLE_BODY2 = 3;
    const STYLE_BODY4 = 4;
    const STYLE_BODY_TEXT = 5;
    const STYLE_PERCENT = 6;
    const STYLE_TITLE = 7;

    private static function style_index($style) {
        switch ($style) {
            case 'header': return self::STYLE_HEADER;
            case 'body0': return self::STYLE_BODY0;
            case 'body2': return self::STYLE_BODY2;
            case 'body4': return self::STYLE_BODY4;
            case 'percent': return self::STYLE_PERCENT;
            case 'title': return self::STYLE_TITLE;
            default: return self::STYLE_BODY_TEXT;
        }
    }

    private static function col_letter($index0) {
        $letters = '';
        $n = $index0 + 1;
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $n = intdiv($n - 1, 26);
        }
        return $letters;
    }

    private function sheet_xml($sheet) {
        $rows = $sheet['rows'];
        $row_count = count($rows);
        $col_count = $rows ? max(array_map('count', $rows)) : 0;
        $last_col = $col_count > 0 ? self::col_letter($col_count - 1) : 'A';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<dimension ref="A1:' . $last_col . max(1, $row_count) . '"/>';
        $xml .= '<sheetViews><sheetView' . (!empty($sheet['rtl']) ? ' rightToLeft="1"' : '') . ' workbookViewId="0">';
        if ($row_count > 1) {
            $xml .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
        }
        $xml .= '</sheetView></sheetViews>';

        if (!empty($sheet['widths'])) {
            $xml .= '<cols>';
            foreach ($sheet['widths'] as $i => $width) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $width . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($rows as $r => $row) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $cell) {
                $ref = self::col_letter($c) . ($r + 1);
                $style = self::style_index(isset($cell['style']) ? $cell['style'] : 'text');
                $value = array_key_exists('v', $cell) ? $cell['v'] : null;

                if ($value === null) {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"/>';
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . self::num($value) . '</v></c>';
                } else {
                    $sid = $this->string_id($value);
                    $xml .= '<c r="' . $ref . '" s="' . $style . '" t="s"><v>' . $sid . '</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if ($col_count > 0 && $row_count > 1) {
            $xml .= '<autoFilter ref="A1:' . $last_col . $row_count . '"/>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    private static function num($value) {
        if (is_int($value)) {
            return (string) $value;
        }
        // Avoid scientific notation and trim trailing zeros; Excel wants plain decimals.
        $s = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        return $s === '' || $s === '-' ? '0' : $s;
    }

    private function shared_strings_xml() {
        $count = count($this->shared_strings);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';
        foreach ($this->shared_strings as $s) {
            $xml .= '<si><t xml:space="preserve">' . self::escape($s) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    private static function escape($text) {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function styles_xml() {
        // numFmtId 3 = builtin "#,##0"; 164/165 are custom (2 and 4 decimals).
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<numFmts count="3">' .
            '<numFmt numFmtId="164" formatCode="#,##0.00"/>' .
            '<numFmt numFmtId="165" formatCode="#,##0.0000"/>' .
            '<numFmt numFmtId="166" formatCode="0.00%"/>' .
            '</numFmts>' .
            '<fonts count="3">' .
            '<font><sz val="10"/><name val="Vazirmatn"/></font>' .
            '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Vazirmatn"/></font>' .
            '<font><b/><sz val="13"/><color rgb="FF1F3A5F"/><name val="Vazirmatn"/></font>' .
            '</fonts>' .
            '<fills count="3">' .
            '<fill><patternFill patternType="none"/></fill>' .
            '<fill><patternFill patternType="gray125"/></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FF1F3A5F"/><bgColor indexed="64"/></patternFill></fill>' .
            '</fills>' .
            '<borders count="2">' .
            '<border><left/><right/><top/><bottom/><diagonal/></border>' .
            '<border>' .
            '<left style="thin"><color rgb="FFD8DEE9"/></left>' .
            '<right style="thin"><color rgb="FFD8DEE9"/></right>' .
            '<top style="thin"><color rgb="FFD8DEE9"/></top>' .
            '<bottom style="thin"><color rgb="FFD8DEE9"/></bottom>' .
            '<diagonal/>' .
            '</border>' .
            '</borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="8">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' . // 0 default
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' . // 1 header
            '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' . // 2 body, 0 decimals
            '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' . // 3 body, 2 decimals
            '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' . // 4 body, 4 decimals
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' . // 5 body text
            '<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' . // 6 percent
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>' . // 7 title
            '</cellXfs>' .
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
            '</styleSheet>';
    }

    private function workbook_xml() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';
        foreach ($this->sheets as $i => $sheet) {
            $xml .= '<sheet name="' . self::escape($sheet['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        $xml .= '</sheets></workbook>';
        return $xml;
    }

    private function workbook_rels_xml() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $sheet) {
            $xml .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $n = count($this->sheets);
        $xml .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $xml .= '<Relationship Id="rId' . ($n + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $xml .= '</Relationships>';
        return $xml;
    }

    private function content_types_xml() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        foreach ($this->sheets as $i => $sheet) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        $xml .= '</Types>';
        return $xml;
    }

    private function root_rels_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>';
    }

    /** @return string binary .xlsx content (write with file_put_contents(), no further encoding). */
    public function save() {
        $tmp = tempnam(sys_get_temp_dir(), 'gcxlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('امکان ساخت فایل اکسل موقت وجود نداشت.');
        }

        $zip->addFromString('[Content_Types].xml', $this->content_types_xml());
        $zip->addFromString('_rels/.rels', $this->root_rels_xml());
        $zip->addFromString('xl/workbook.xml', $this->workbook_xml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbook_rels_xml());
        $zip->addFromString('xl/styles.xml', $this->styles_xml());

        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheet_xml($sheet));
        }

        // Shared strings must be finalised last (sheet_xml() populates it as it runs).
        $zip->addFromString('xl/sharedStrings.xml', $this->shared_strings_xml());
        $zip->close();

        $data = file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    // -- high-level report builder, mirrors priceceawler/report.py::to_xlsx --

    /**
     * @param array $series_list [['symbol' => ..., 'rows' => ..., 'stats' => ...], ...]
     * @param array $start [y, m, d]
     * @param array $end   [y, m, d]
     */
    public static function build_report($series_list, $start, $end, $app_name, $version) {
        $xlsx = new self();

        // --- summary sheet ---------------------------------------------
        $header = array('نماد', 'شناسه TGJU', 'واحد', 'روزهای معاملاتی', 'اولین قیمت', 'آخرین قیمت', 'کمترین', 'بیشترین', 'میانگین', 'تغییر', 'درصد تغییر');
        $summary_rows = array();
        $summary_rows[] = array(array('v' => $app_name . ' — گزارش قیمت‌های TGJU', 'style' => 'title'));
        $summary_rows[] = array(array('v' => sprintf(
            'بازه: %s تا %s   |   نسخه %s',
            GC_Jalali::format($start[0], $start[1], $start[2]), GC_Jalali::format($end[0], $end[1], $end[2]), $version
        ), 'style' => 'text'));
        $summary_rows[] = array_map(function ($h) { return array('v' => $h, 'style' => 'header'); }, $header);

        foreach ($series_list as $series) {
            $symbol = $series['symbol'];
            $stats = $series['stats'];
            $style_price = $symbol['decimals'] >= 4 ? 'body4' : ($symbol['decimals'] >= 2 ? 'body2' : 'body0');
            $summary_rows[] = array(
                array('v' => $symbol['name'], 'style' => 'text'),
                array('v' => $symbol['key'], 'style' => 'text'),
                array('v' => $stats['unit'], 'style' => 'text'),
                array('v' => $stats['trading_days'], 'style' => 'body0'),
                array('v' => $stats['first'], 'style' => $style_price),
                array('v' => $stats['last'], 'style' => $style_price),
                array('v' => $stats['min'], 'style' => $style_price),
                array('v' => $stats['max'], 'style' => $style_price),
                array('v' => $stats['mean'], 'style' => $style_price),
                array('v' => $stats['change'], 'style' => $style_price),
                array('v' => $stats['change_pct'] !== null ? $stats['change_pct'] / 100 : null, 'style' => 'percent'),
            );
        }
        $xlsx->add_sheet('خلاصه گزارش', $summary_rows, array(24, 16, 10, 14, 14, 14, 14, 14, 14, 12, 12));
        $xlsx->sheets[count($xlsx->sheets) - 1]['rtl'] = true;

        // --- one sheet per symbol ---------------------------------------
        foreach ($series_list as $series) {
            $symbol = $series['symbol'];
            $style_price = $symbol['decimals'] >= 4 ? 'body4' : ($symbol['decimals'] >= 2 ? 'body2' : 'body0');
            $rows = array();
            $rows[] = array_map(function ($h) { return array('v' => $h, 'style' => 'header'); }, GC_Report::COLUMNS);
            foreach ($series['rows'] as $row) {
                $rows[] = array(
                    array('v' => $row['date'], 'style' => 'text'),
                    array('v' => $row['weekday'], 'style' => 'text'),
                    array('v' => $row['low'], 'style' => $style_price),
                    array('v' => $row['high'], 'style' => $style_price),
                    array('v' => $row['close'], 'style' => $style_price),
                    array('v' => $row['average'], 'style' => $style_price),
                    array('v' => $row['status'], 'style' => 'text'),
                );
            }
            $xlsx->add_sheet($symbol['name'], $rows, array(14, 12, 16, 16, 16, 20, 26));
            $xlsx->sheets[count($xlsx->sheets) - 1]['rtl'] = true;
        }

        return $xlsx->save();
    }
}
