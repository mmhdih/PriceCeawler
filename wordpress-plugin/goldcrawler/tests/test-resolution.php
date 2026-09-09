<?php
/**
 * Tests that the chosen resolution actually reaches the report/export layer:
 * GC_Crawler::build_at() must dispatch daily to TGJU and intraday to the
 * recorded samples, and the CSV/XLSX writers must grow a time column for
 * intraday rows without changing the daily output at all.
 */

require __DIR__ . '/wp-stubs.php';
define('GC_STANDALONE_TEST', true);
define('GOLDCRAWLER_VERSION', '1.4.0');
require __DIR__ . '/../includes/class-gc-jalali.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-tgju.php';
require __DIR__ . '/../includes/class-gc-report.php';
require __DIR__ . '/../includes/class-gc-storage.php';
require __DIR__ . '/../includes/class-gc-crawler.php';
require __DIR__ . '/../includes/class-gc-intraday.php';
require __DIR__ . '/../includes/class-gc-xlsx.php';

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

$symbol = array('key' => 'geram18', 'name' => 'طلای ۱۸ عیار', 'group' => 'طلا و نقره',
    'currency' => 'IRR', 'decimals' => 0);

// 1404/05/28 == 2025-08-19; 06:00 UTC == 09:30 Tehran.
$base = gmmktime(6, 0, 0, 8, 19, 2025);
$start = array(1404, 5, 28);
$end = array(1404, 5, 28);

GC_Intraday::record('geram18', 7000000, $base);
GC_Intraday::record('geram18', 7020000, $base + 120);
GC_Intraday::record('geram18', 7010000, $base + 700); // next 10m bucket

// -- daily dispatch still goes to TGJU --------------------------------------
gc_test_stub_remote_get('geram18', array('code' => 200, 'body' => json_encode(array('data' => array(
    array('70000000', '69900000', '70100000', '70000000', '', '', '2025-08-19', '1404/05/28'),
)))));

$daily = GC_Crawler::build_at(array('geram18'), $start, $end, true, true, 'daily');
gc_check($daily['resolution'] === 'daily', 'the daily result reports its resolution');
gc_check(count($daily['series']) === 1, 'the daily path builds a series from TGJU');
gc_check(!isset($daily['series'][0]['rows'][0]['time']), 'daily rows carry no clock time');
gc_check($daily['series'][0]['rows'][0]['close'] === 7000000, 'the daily close comes from the TGJU row (divided to Toman)');

// -- intraday dispatch reads our own samples, not TGJU ----------------------
foreach (array('10m' => 2, '1h' => 1, 'tick' => 3) as $resolution => $expected) {
    $result = GC_Crawler::build_at(array('geram18'), $start, $end, true, false, $resolution);
    gc_check($result['resolution'] === $resolution, "the {$resolution} result reports its resolution");
    gc_check(count($result['series']) === 1, "the {$resolution} path builds a series");
    gc_check(count($result['series'][0]['rows']) === $expected, "the {$resolution} path produces {$expected} row(s) from the recorded samples");
    gc_check(isset($result['series'][0]['rows'][0]['time']), "{$resolution} rows carry a clock time");
}

// An unknown resolution must not silently produce intraday rows.
$fallback = GC_Crawler::build_at(array('geram18'), $start, $end, true, true, 'every-nanosecond');
gc_check($fallback['resolution'] === 'daily', 'an unknown resolution falls back to daily rather than erroring');

// A symbol with no recorded samples is reported as an error, not silently empty.
$missing = GC_Crawler::build_at(array('sekee'), $start, $end, true, false, '10m');
gc_check($missing['series'] === array(), 'a symbol with no intraday samples yields no series');
gc_check(
    count($missing['errors']) === 1 && strpos($missing['errors'][0]['message'], 'نمونه درون‌روزی') !== false,
    'the user is told explicitly that no intraday samples were recorded for that symbol/range'
);

// -- CSV: daily output unchanged, intraday grows a time column --------------
$daily_csv = GC_Report::to_csv($daily['series']);
gc_check(strpos($daily_csv, 'ساعت') === false, 'the daily CSV has no time column (unchanged behaviour)');
gc_check(strpos($daily_csv, 'وضعیت') !== false, 'the daily CSV still carries its status column');

$intraday_series = GC_Crawler::build_at(array('geram18'), $start, $end, true, false, '10m')['series'];
$intraday_csv = GC_Report::to_csv($intraday_series);
gc_check(strpos($intraday_csv, 'ساعت') !== false, 'the intraday CSV gains a time column');
gc_check(strpos($intraday_csv, 'تعداد نمونه') !== false, 'the intraday CSV reports how many samples each row came from');
gc_check(strpos($intraday_csv, '09:30') !== false, 'the intraday CSV contains the actual bucket time');
gc_check(substr_count($intraday_csv, "\n") === 3, 'the intraday CSV has one header row plus one row per bucket');

gc_check(GC_Report::rows_are_intraday($intraday_series[0]['rows']) === true, 'intraday rows are detected as intraday');
gc_check(GC_Report::rows_are_intraday($daily['series'][0]['rows']) === false, 'daily rows are not mistaken for intraday');
gc_check(GC_Report::rows_are_intraday(array()) === false, 'an empty row set is not mistaken for intraday');

// -- XLSX: both shapes must produce a valid workbook -----------------------
foreach (array('daily' => $daily['series'], 'intraday' => $intraday_series) as $label => $series) {
    $bytes = GC_Xlsx::build_report($series, $start, $end, 'GoldCrawler', GOLDCRAWLER_VERSION);
    $path = sys_get_temp_dir() . "/gc-res-{$label}-" . getmypid() . '.xlsx';
    file_put_contents($path, $bytes);

    $zip = new ZipArchive();
    gc_check($zip->open($path) === true, "the {$label} xlsx is a valid zip archive");
    $sheet = $zip->getFromName('xl/worksheets/sheet2.xml');
    gc_check($sheet !== false, "the {$label} xlsx has a per-symbol sheet");
    $shared = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    unlink($path);

    if ($label === 'intraday') {
        gc_check(strpos($shared, 'ساعت') !== false, 'the intraday workbook has a time column header');
        gc_check(strpos($shared, '09:30') !== false, 'the intraday workbook contains the bucket time');
    } else {
        gc_check(strpos($shared, 'ساعت') === false, 'the daily workbook has no time column');
        gc_check(strpos($shared, 'وضعیت') !== false, 'the daily workbook keeps its status column');
    }
}

// -- JSON payload carries the intraday rows verbatim -----------------------
$payload = GC_Report::to_json_payload($intraday_series, $start, $end, 'GoldCrawler', GOLDCRAWLER_VERSION);
gc_check(isset($payload['series'][0]['rows'][0]['time']), 'the JSON export keeps the time of each intraday row');
gc_check(isset($payload['series'][0]['rows'][0]['samples']), 'the JSON export keeps the sample count of each intraday row');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
