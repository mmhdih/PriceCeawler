<?php
/**
 * Builds a sample workbook and writes it to disk so the harness script
 * (validate_xlsx.sh) can check it with both openpyxl and LibreOffice —
 * "PHP didn't throw" is not evidence a hand-written OOXML file is valid.
 */

define('GC_STANDALONE_TEST', true);
require __DIR__ . '/../includes/class-gc-jalali.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-report.php';
require __DIR__ . '/../includes/class-gc-xlsx.php';

function gc_point($close) {
    return array('close' => $close, 'low' => $close - 1000, 'high' => $close + 1000, 'average' => $close);
}

$gold = GC_Symbols::get('geram18');
$ons = GC_Symbols::get('ons');
$tether = GC_Symbols::get('crypto-tether');

$start = array(1404, 5, 1);
$end = array(1404, 5, 10);

$points_gold = array();
$points_ons = array();
$points_tether = array();
foreach (GC_Jalali::date_range($start[0], $start[1], $start[2], $end[0], $end[1], $end[2]) as $i => $date) {
    if ($i % 3 !== 1) { // skip some days to exercise "carried" rows
        $points_gold[$date] = gc_point(7000000 + $i * 12345);
        $points_ons[$date] = gc_point(3300 + $i * 1.25);
        $points_tether[$date] = gc_point(58000 + $i * 3);
    }
}

$series_list = array(
    array('symbol' => $gold, 'rows' => GC_Report::build_series($gold, $points_gold, $start, $end)['rows'],
          'stats' => GC_Report::build_series($gold, $points_gold, $start, $end)['stats']),
    array('symbol' => $ons, 'rows' => GC_Report::build_series($ons, $points_ons, $start, $end)['rows'],
          'stats' => GC_Report::build_series($ons, $points_ons, $start, $end)['stats']),
    array('symbol' => $tether, 'rows' => GC_Report::build_series($tether, $points_tether, $start, $end)['rows'],
          'stats' => GC_Report::build_series($tether, $points_tether, $start, $end)['stats']),
);

// A name deliberately long enough (and with characters Excel forbids in
// sheet names) to exercise GC_Xlsx::sanitise_sheet_name().
$series_list[0]['symbol']['name'] = 'نمادی با نام بسیار بسیار بسیار بسیار طولانی برای آزمایش [تست]';

$bytes = GC_Xlsx::build_report($series_list, $start, $end, 'GoldCrawler', '1.0.0');

$out = $argv[1] ?? (sys_get_temp_dir() . '/gc-sample.xlsx');
file_put_contents($out, $bytes);
fwrite(STDERR, "wrote {$out} (" . strlen($bytes) . " bytes)\n");

// Sanity: it must at least be a well-formed zip with the expected parts.
$zip = new ZipArchive();
$ok = $zip->open($out) === true;
if (!$ok) {
    fwrite(STDERR, "FAIL: not a valid zip archive\n");
    exit(1);
}
$required = array('[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml');
$failures = 0;
foreach ($required as $part) {
    if ($zip->locateName($part) === false) {
        fwrite(STDERR, "FAIL: missing zip entry {$part}\n");
        $failures++;
    }
}
$zip->close();

echo "zip structure checks: " . (count($required) - $failures) . "/" . count($required) . " ok\n";
exit($failures > 0 ? 1 : 0);
