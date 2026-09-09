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
require __DIR__ . '/../includes/class-gc-tgju-intraday.php';
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

// With no chart endpoint stubbed, extraction fails and (in "auto") the
// recorded samples answer instead - the whole point of the fallback.
$missing = GC_Crawler::build_at(array('sekee'), $start, $end, true, false, '10m');
gc_check($missing['series'] === array(), 'a symbol with neither extraction nor samples yields no series');
gc_check(count($missing['errors']) === 1, 'the symbol with no data produces exactly one error');
gc_check(
    strpos($missing['errors'][0]['message'], 'سرویس نمودار') !== false,
    'in auto mode the reported failure is the extraction failure'
);

// Forcing the recorded source produces the recording-specific guidance:
// "no samples" alone would be a dead end, so the message must carry a next
// step and admit that past intraday data cannot be recovered.
GC_Storage::update_settings(array('intraday_source' => 'recorded'));

$missing = GC_Crawler::build_at(array('sekee'), $start, $end, true, false, '10m');
$gc_msg = $missing['errors'][0]['message'];
gc_check(strpos($gc_msg, 'ثبت خودکار') !== false, 'the message names the recording switch the user has to turn on');
gc_check(strpos($gc_msg, 'قابل بازیابی نیست') !== false, 'the message admits past intraday data cannot be recovered');

// A symbol that *does* have samples, asked for a range outside them, gets a
// different message naming the window that actually has data.
$gc_far = array(1403, 1, 1);
$gc_outside = GC_Crawler::build_at(array('geram18'), $gc_far, $gc_far, true, false, '10m');
$gc_outside_msg = $gc_outside['errors'][0]['message'];
gc_check(strpos($gc_outside_msg, 'بیرون از این محدوده') !== false, 'a range outside the recorded days says so');
gc_check(strpos($gc_outside_msg, '۱۴۰۴/۰۵/۲۸') !== false, 'that message names the first recorded Jalali day in Persian digits');

// -- extraction is preferred over recorded samples in "auto" ----------------
GC_Storage::update_settings(array('intraday_source' => 'auto', 'intraday_endpoint' => ''));

// The daily stub above is keyed on 'geram18', which also appears inside the
// chart URLs - clear the registry so it cannot answer for the chart service.
$GLOBALS['gc_test_remote_get_responses'] = array();

// Bars whose prices differ from the recorded samples, so we can tell which
// source actually answered.
gc_test_stub_remote_get('platform.tgju.org', array('code' => 200, 'body' => json_encode(array(
    's' => 'ok',
    't' => array($base, $base + 60),
    'o' => array(80000000, 80100000),
    'h' => array(80500000, 80600000),
    'l' => array(79900000, 80000000),
    'c' => array(80100000, 80400000),
))));

// A key with NO stored samples, so this isolates extraction; a key that has
// both is covered by the merge section below.
$extracted = GC_Crawler::build_at(array('extract_only'), $start, $end, true, false, '10m');
gc_check(count($extracted['series']) === 1, 'extraction alone produces a series');
$xrow = $extracted['series'][0]['rows'][0];
gc_check($xrow['close'] === 8040000, 'the rows came from extraction');
gc_check($xrow['high'] === 8060000, 'the bucket high is the max of the extracted bar highs');
gc_check($xrow['low'] === 7990000, 'the bucket low is the min of the extracted bar lows');
gc_check($xrow['samples'] === 2, 'the sample count is the number of extracted bars');
gc_check(
    GC_Storage::get_settings()['intraday_endpoint'] === 'platform-tvdata',
    'the endpoint that answered is pinned so later requests skip probing'
);

// "recorded" must ignore the (now working) chart service entirely.
GC_Storage::update_settings(array('intraday_source' => 'recorded'));
$forced = GC_Crawler::build_at(array('geram18'), $start, $end, true, false, '10m');
gc_check($forced['series'][0]['rows'][0]['close'] === 7020000,
    'forcing the recorded source ignores the chart service');

GC_Storage::update_settings(array('intraday_source' => 'auto', 'intraday_endpoint' => ''));
$GLOBALS['gc_test_remote_get_responses'] = array();
gc_test_stub_remote_get('geram18', array('code' => 200, 'body' => json_encode(array('data' => array(
    array('70000000', '69900000', '70100000', '70000000', '', '', '2025-08-19', '1404/05/28'),
)))));

// -- stored and extracted data are MERGED, not chosen between ---------------
// The reported bug: TGJU's page only carries today, so preferring extraction
// over the store silently dropped every earlier day of the range.

$GLOBALS['gc_test_remote_get_responses'] = array();
GC_Storage::update_settings(array('intraday_source' => 'auto', 'intraday_endpoint' => ''));

// Yesterday exists only in the local store...
// Stored prices are already in display units - the sampler divides before
// recording - unlike the raw rial the chart response carries.
$gc_yesterday_base = $base - 86400;              // 1404/05/27, 09:30 Tehran
GC_Intraday::record('merge_test', 6000000, $gc_yesterday_base);
GC_Intraday::record('merge_test', 6010000, $gc_yesterday_base + 600);

// ...and today only in the live chart response.
gc_test_stub_remote_get('tgju.org', array('code' => 200, 'body' => json_encode(array(
    's' => 'ok',
    't' => array($base, $base + 600),
    'c' => array(70000000, 70200000),
))));

$gc_span = GC_Crawler::build_at(
    array('merge_test'), array(1404, 5, 27), array(1404, 5, 28), true, false, '10m'
);
gc_check(count($gc_span['series']) === 1, 'a two-day intraday range builds a series');
$gc_days = array_unique(array_column($gc_span['series'][0]['rows'], 'date'));
sort($gc_days);
gc_check(
    $gc_days === array('1404/05/27', '1404/05/28'),
    'the range covers BOTH the stored day and the extracted day'
);
gc_check(count($gc_span['series'][0]['rows']) === 4, 'every bucket from both sources is present');

// The stored day survives even when the chart source answers for today.
$gc_rows = $gc_span['series'][0]['rows'];
$gc_first = $gc_rows[0];
gc_check($gc_first['date'] === '1404/05/27' && $gc_first['close'] === 6000000,
    'the earlier stored day is the first row, with its own price');

// Live data wins for a timestamp present in both, since the store was
// harvested from it in the first place.
GC_Intraday::record('merge_test', 1111111, $base);   // a stale stored copy
$gc_span = GC_Crawler::build_at(
    array('merge_test'), array(1404, 5, 28), array(1404, 5, 28), true, false, 'tick'
);
$gc_closes = array_column($gc_span['series'][0]['rows'], 'close');
gc_check(!in_array(1111111, $gc_closes, true) && in_array(7000000, $gc_closes, true),
    'extracted data wins over a stored copy of the same timestamp');

// "recorded" still ignores the chart source entirely.
GC_Storage::update_settings(array('intraday_source' => 'recorded'));
$gc_only_stored = GC_Crawler::build_at(
    array('merge_test'), array(1404, 5, 27), array(1404, 5, 27), true, false, '10m'
);
gc_check(count($gc_only_stored['series'][0]['rows']) === 2,
    'forcing the recorded source still reads only the store');

// "tgju" ignores the store entirely.
GC_Storage::update_settings(array('intraday_source' => 'tgju'));
$gc_only_live = GC_Crawler::build_at(
    array('merge_test'), array(1404, 5, 27), array(1404, 5, 28), true, false, '10m'
);
$gc_days = array_unique(array_column($gc_only_live['series'][0]['rows'], 'date'));
gc_check($gc_days === array('1404/05/28'),
    'forcing the TGJU source reports only what the chart returned');

GC_Storage::update_settings(array('intraday_source' => 'auto'));
$GLOBALS['gc_test_remote_get_responses'] = array();
gc_test_stub_remote_get('geram18', array('code' => 200, 'body' => json_encode(array('data' => array(
    array('70000000', '69900000', '70100000', '70000000', '', '', '2025-08-19', '1404/05/28'),
)))));

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
        // The summary counts time buckets here, so it must not call them days.
        gc_check(strpos($shared, 'ردیف‌های زمانی') !== false, 'the intraday summary counts time rows');
        gc_check(strpos($shared, 'روزهای معاملاتی') === false, 'the intraday summary does not mislabel buckets as trading days');
    } else {
        gc_check(strpos($shared, 'ساعت') === false, 'the daily workbook has no time column');
        gc_check(strpos($shared, 'وضعیت') !== false, 'the daily workbook keeps its status column');
        gc_check(strpos($shared, 'روزهای معاملاتی') !== false, 'the daily summary still counts trading days');
    }
}

// -- JSON payload carries the intraday rows verbatim -----------------------
$payload = GC_Report::to_json_payload($intraday_series, $start, $end, 'GoldCrawler', GOLDCRAWLER_VERSION);
gc_check(isset($payload['series'][0]['rows'][0]['time']), 'the JSON export keeps the time of each intraday row');
gc_check(isset($payload['series'][0]['rows'][0]['samples']), 'the JSON export keeps the sample count of each intraday row');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
