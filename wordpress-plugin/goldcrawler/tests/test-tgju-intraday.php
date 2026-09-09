<?php
/**
 * Tests the intraday *extraction* path: GC_TGJU_Intraday must turn TGJU's
 * chart responses into candles, try the candidate endpoints in order, and
 * report clearly when none of them answers.
 */

require __DIR__ . '/wp-stubs.php';
define('GC_STANDALONE_TEST', true);
define('GOLDCRAWLER_VERSION', '1.6.0');
require __DIR__ . '/../includes/class-gc-jalali.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-tgju.php';
require __DIR__ . '/../includes/class-gc-report.php';
require __DIR__ . '/../includes/class-gc-storage.php';
require __DIR__ . '/../includes/class-gc-intraday.php';
require __DIR__ . '/../includes/class-gc-tgju-intraday.php';

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

$gold = GC_Symbols::get('geram18');   // IRR -> divided by 10 for Toman
$ons = GC_Symbols::get('ons');        // USD -> undivided

function gc_udf($payload) {
    return array('code' => 200, 'body' => json_encode($payload));
}

function gc_reset_remote() {
    $GLOBALS['gc_test_remote_get_responses'] = array();
}

// -- parse_udf --------------------------------------------------------------

$candles = GC_TGJU_Intraday::parse_udf(array(
    's' => 'ok',
    't' => array(1700000000, 1700000600),
    'o' => array(69900000, 70000000),
    'h' => array(70200000, 70300000),
    'l' => array(69800000, 69950000),
    'c' => array(70000000, 70100000),
), $gold);
gc_check(count($candles) === 2, 'a UDF payload becomes one candle per timestamp');
gc_check($candles[0]['close'] === 7000000.0, 'rial prices are divided by 10 to Toman');
gc_check($candles[0]['open'] === 6990000.0, 'the open is converted too');
gc_check($candles[0]['high'] === 7020000.0, 'the high is converted too');
gc_check($candles[0]['low'] === 6980000.0, 'the low is converted too');
gc_check($candles[1]['ts'] === 1700000600, 'the timestamp is preserved');

$dollar = GC_TGJU_Intraday::parse_udf(
    array('s' => 'ok', 't' => array(1700000000), 'c' => array(3301.25)), $ons
);
gc_check($dollar[0]['close'] === 3301.25, 'dollar symbols are not divided');

gc_check(
    GC_TGJU_Intraday::parse_udf(array('s' => 'no_data', 't' => array()), $gold) === array(),
    'no_data is an empty answer, not an error (that range simply has no bars)'
);

$threw = false;
try {
    GC_TGJU_Intraday::parse_udf(array('s' => 'error', 'errmsg' => 'unknown_symbol'), $gold);
} catch (GC_TGJU_Intraday_Error $exc) {
    $threw = strpos($exc->getMessage(), 'unknown_symbol') !== false;
}
gc_check($threw, 'an error status raises, carrying the server message');

$threw = false;
try {
    GC_TGJU_Intraday::parse_udf(array('s' => 'weird', 't' => array(1)), $gold);
} catch (GC_TGJU_Intraday_Error $exc) { $threw = true; }
gc_check($threw, 'an unknown status is not silently accepted');

$threw = false;
try {
    GC_TGJU_Intraday::parse_udf(array('s' => 'ok', 'c' => array(1, 2)), $gold);
} catch (GC_TGJU_Intraday_Error $exc) { $threw = true; }
gc_check($threw, 'a payload with no time column is an error');

$ms = GC_TGJU_Intraday::parse_udf(
    array('s' => 'ok', 't' => array(1700000000000), 'c' => array(70000000)), $gold
);
gc_check($ms[0]['ts'] === 1700000000, 'millisecond timestamps are normalised to seconds');

$sparse = GC_TGJU_Intraday::parse_udf(
    array('s' => 'ok', 't' => array(1, 2, 3), 'c' => array(70000000, null, 0)), $gold
);
gc_check(count($sparse) === 1, 'bars with no usable price at all are dropped');

$unsorted = GC_TGJU_Intraday::parse_udf(
    array('s' => 'ok', 't' => array(300, 100, 200), 'c' => array(3.0, 1.0, 2.0)), $ons
);
gc_check(
    array_column($unsorted, 'ts') === array(100, 200, 300),
    'candles come back in time order regardless of the response order'
);

// A feed sending fewer highs than times must not blow up.
$short = GC_TGJU_Intraday::parse_udf(
    array('s' => 'ok', 't' => array(1, 2), 'c' => array(10.0, 20.0), 'h' => array(11.0)), $ons
);
gc_check($short[0]['high'] === 11.0 && $short[1]['high'] === null,
    'short price columns yield null rather than an error');

// -- parse_rows -------------------------------------------------------------

$rows = GC_TGJU_Intraday::parse_rows(
    array('data' => array(array(1700000000, 69900000, 70200000, 69800000, 70000000))), $gold
);
gc_check($rows[0]['open'] === 6990000.0 && $rows[0]['close'] === 7000000.0,
    'list rows are read as [ts, open, high, low, close]');

$named = GC_TGJU_Intraday::parse_rows(array('data' => array(
    array('time' => 1700000000, 'open' => 1.0, 'high' => 3.0, 'low' => 0.5, 'close' => 2.0),
)), $ons);
gc_check($named[0]['high'] === 3.0, 'dict rows are read by field name');

$junk = GC_TGJU_Intraday::parse_rows(
    array('data' => array(null, 'x', array(), array(1700000000, 5.0))), $ons
);
gc_check(count($junk) === 1, 'junk rows are skipped rather than fatal');

// -- endpoint selection -----------------------------------------------------

gc_check(count(GC_TGJU_Intraday::endpoints_from_setting('')) === count(GC_TGJU_Intraday::candidates()),
    'an empty setting tries every candidate');
gc_check(count(GC_TGJU_Intraday::endpoints_from_setting('   ')) === count(GC_TGJU_Intraday::candidates()),
    'whitespace counts as empty');

$pinned = GC_TGJU_Intraday::endpoints_from_setting('api-tvdata');
gc_check(count($pinned) === 1 && $pinned[0]['name'] === 'api-tvdata',
    'a known candidate name pins exactly that endpoint');
gc_check(count(GC_TGJU_Intraday::endpoints_from_setting('nope')) === count(GC_TGJU_Intraday::candidates()),
    'an unknown name falls back to probing everything');

$template = 'https://x.example/history?symbol={symbol}&resolution={resolution}&from={from}&to={to}';
$custom = GC_TGJU_Intraday::endpoints_from_setting($template);
gc_check(count($custom) === 1 && $custom[0]['name'] === 'custom' && $custom[0]['url'] === $template,
    'a URL template becomes a single custom endpoint');

$url = GC_TGJU_Intraday::build_url(GC_TGJU_Intraday::candidates()[0], 'geram18', '10', 100, 200);
gc_check(strpos($url, 'symbol=geram18') !== false, 'the built URL carries the symbol');
gc_check(strpos($url, 'resolution=10') !== false, 'the built URL carries the resolution');
gc_check(strpos($url, 'from=100') !== false && strpos($url, 'to=200') !== false,
    'the built URL carries the range');
gc_check(strpos($url, '{') === false, 'no placeholder is left unreplaced');

$upper = GC_TGJU_Intraday::build_url(GC_TGJU_Intraday::candidates()[1], 'crypto-bitcoin', '1', 0, 1);
gc_check(strpos($upper, 'symbol=CRYPTO_BITCOIN') !== false,
    'the upper_underscore style upper-cases and swaps dashes');

// -- windows ----------------------------------------------------------------

gc_check(GC_TGJU_Intraday::windows(0, 100, 1000) === array(array(0, 100)),
    'a short range is a single window');
$windows = GC_TGJU_Intraday::windows(0, 2500, 1000);
gc_check($windows[0][0] === 0 && $windows[count($windows) - 1][1] === 2500,
    'a long range is split but still covers the whole span');
$contiguous = true;
for ($i = 1; $i < count($windows); $i++) {
    if ($windows[$i][0] !== $windows[$i - 1][1] + 1) { $contiguous = false; }
}
gc_check($contiguous, 'windows are contiguous and non-overlapping');
gc_check(GC_TGJU_Intraday::windows(500, 100, 1000) === array(),
    'an inverted range yields no windows');

// -- fetch_candles ----------------------------------------------------------

gc_reset_remote();
gc_test_stub_remote_get('platform.tgju.org', gc_udf(
    array('s' => 'ok', 't' => array(1700000000), 'c' => array(70000000))
));
$fetched = GC_TGJU_Intraday::fetch_candles($gold, 1700000000, 1700003600);
gc_check($fetched['endpoint'] === 'platform-tvdata', 'the first working endpoint is reported');
gc_check($fetched['resolution'] === '1', 'the finest working resolution is reported');
gc_check(count($fetched['candles']) === 1, 'the candles come back');

// A dead first candidate must not stop the search.
gc_reset_remote();
gc_test_stub_remote_get('api.tgju.org/v1/tvdata', gc_udf(
    array('s' => 'ok', 't' => array(1700000000), 'c' => array(70000000))
));
$fetched = GC_TGJU_Intraday::fetch_candles($gold, 1700000000, 1700003600);
gc_check($fetched['endpoint'] === 'api-tvdata',
    'an unreachable candidate is skipped for the next one');

// Candles outside the asked-for range are not smuggled in.
gc_reset_remote();
gc_test_stub_remote_get('platform.tgju.org', gc_udf(array(
    's' => 'ok', 't' => array(1699000000, 1700000000, 1799000000),
    'c' => array(1.0, 2.0, 3.0),
)));
$fetched = GC_TGJU_Intraday::fetch_candles($ons, 1700000000, 1700003600);
gc_check(array_column($fetched['candles'], 'ts') === array(1700000000),
    'candles outside the requested range are dropped');

// Nothing answers at all.
gc_reset_remote();
$threw = false;
try {
    GC_TGJU_Intraday::fetch_candles($gold, 1700000000, 1700003600);
} catch (GC_TGJU_Intraday_Error $exc) {
    $threw = strpos($exc->getMessage(), 'تلاش‌ها') !== false;
}
gc_check($threw, 'when no endpoint answers, the error names the attempts');

// An inverted range fails before any request is made.
gc_reset_remote();
$threw = false;
try {
    GC_TGJU_Intraday::fetch_candles($gold, 200, 100);
} catch (GC_TGJU_Intraday_Error $exc) { $threw = true; }
gc_check($threw, 'an inverted range is rejected up front');

// A pinned endpoint is the only one contacted.
gc_reset_remote();
$requested = array();
gc_test_stub_remote_get('tgju.org', function ($url) use (&$requested) {
    $requested[] = $url;
    return gc_udf(array('s' => 'ok', 't' => array(1700000000), 'c' => array(1.0)));
});
GC_TGJU_Intraday::fetch_candles(
    $ons, 1700000000, 1700003600, GC_TGJU_Intraday::endpoints_from_setting('api-tvdata')
);
$only_pinned = true;
foreach ($requested as $url) {
    if (strpos($url, 'api.tgju.org/v1/tvdata') === false) { $only_pinned = false; }
}
gc_check($only_pinned && $requested, 'a pinned endpoint is the only one contacted');

// -- granularity: daily bars must never pass as intraday --------------------
// Regression cover for a real failure: asking for resolution=1 over a week
// returned one bar per day stamped 00:00 UTC (03:30 Tehran), and the plugin
// relabelled them as 10-minute rows. Rows are not evidence of granularity.

function gc_bars($start, $count, $step) {
    $bars = array();
    for ($i = 0; $i < $count; $i++) {
        $bars[] = array('ts' => $start + ($i * $step), 'open' => 1.0,
            'high' => 1.0, 'low' => 1.0, 'close' => 1.0);
    }
    return $bars;
}

$gc_midnight = 1757030400; // exactly a midnight UTC, like the real response

gc_check(GC_TGJU_Intraday::median_gap(array()) === null, 'the median gap of no bars is null');
gc_check(GC_TGJU_Intraday::median_gap(gc_bars($gc_midnight, 1, 60)) === null,
    'the median gap needs at least two bars');
gc_check(GC_TGJU_Intraday::median_gap(gc_bars($gc_midnight, 5, 60)) === 60,
    'the median gap of minute bars is 60 seconds');
gc_check(GC_TGJU_Intraday::median_gap(gc_bars($gc_midnight, 5, 86400)) === 86400,
    'the median gap of daily bars is a day');

// One long market gap must not make minute bars look daily.
$gc_gappy = gc_bars($gc_midnight + 3600, 5, 60);
$gc_gappy[] = array('ts' => $gc_gappy[4]['ts'] + (14 * 3600), 'open' => 1.0,
    'high' => 1.0, 'low' => 1.0, 'close' => 1.0);
gc_check(GC_TGJU_Intraday::median_gap($gc_gappy) === 60,
    'the median ignores a single overnight gap');
gc_check(GC_TGJU_Intraday::is_intraday_spacing($gc_gappy) === true,
    'minute bars with one overnight gap are still intraday');

gc_check(GC_TGJU_Intraday::is_intraday_spacing(gc_bars($gc_midnight, 5, 86400)) === false,
    'daily bars are not intraday');
gc_check(GC_TGJU_Intraday::is_intraday_spacing(gc_bars($gc_midnight + 3600, 5, 60)) === true,
    'minute bars are intraday');
gc_check(GC_TGJU_Intraday::is_intraday_spacing(gc_bars($gc_midnight + 3600, 5, 3600)) === true,
    'hourly bars are intraday');
gc_check(GC_TGJU_Intraday::is_intraday_spacing(array()) === false,
    'no bars at all is not intraday');
// A lone bar is judged by whether it sits on a day boundary.
gc_check(GC_TGJU_Intraday::is_intraday_spacing(gc_bars($gc_midnight, 1, 60)) === false,
    'a lone bar exactly on a day boundary is a daily bar');
gc_check(GC_TGJU_Intraday::is_intraday_spacing(gc_bars($gc_midnight + 600, 1, 60)) === true,
    'a lone bar away from a day boundary counts as intraday');

// The whole bug, end to end: a daily-only feed must raise, not mislabel.
gc_reset_remote();
gc_test_stub_remote_get('tgju.org', function ($url) {
    $start = (int) explode('&', explode('from=', $url)[1])[0];
    $stop = (int) explode('&', explode('to=', $url)[1])[0];
    $first = (int) (ceil($start / 86400) * 86400);
    $times = array();
    for ($ts = $first; $ts <= $stop; $ts += 86400) {
        $times[] = $ts;
    }
    return gc_udf(array('s' => 'ok', 't' => $times,
        'c' => array_fill(0, max(1, count($times)), 70000000)));
});
$threw = false;
$message = '';
try {
    GC_TGJU_Intraday::fetch_candles($gold, $gc_midnight, $gc_midnight + (6 * 86400));
} catch (GC_TGJU_Intraday_Error $exc) {
    $threw = true;
    $message = $exc->getMessage();
}
gc_check($threw, 'a daily-only feed raises instead of returning daily bars');
gc_check(strpos($message, 'فقط داده روزانه') !== false,
    'the error says the feed only returned daily data');

// A feed where only one spelling is honoured must still be found.
gc_reset_remote();
gc_test_stub_remote_get('tgju.org', function ($url) {
    $start = (int) explode('&', explode('from=', $url)[1])[0];
    if (strpos($url, 'resolution=10m&') !== false) {
        $times = array();
        for ($i = 1; $i <= 5; $i++) { $times[] = $start + ($i * 600); }
    } else {
        $times = array();
        for ($i = 0; $i < 5; $i++) { $times[] = $start + ($i * 86400); }
    }
    return gc_udf(array('s' => 'ok', 't' => $times, 'c' => array_fill(0, 5, 70000000)));
});
$found = GC_TGJU_Intraday::fetch_candles($gold, $gc_midnight, $gc_midnight + (6 * 86400));
gc_check($found['resolution'] === '10m',
    'the intraday resolution later in the list is still found');
gc_check(GC_TGJU_Intraday::is_intraday_spacing($found['candles']) === true,
    'the candles it returned really are intraday');

gc_check(in_array('10m', GC_TGJU_Intraday::NATIVE_RESOLUTIONS, true),
    'the resolution list covers minute-suffixed spellings too');
gc_check(GC_TGJU_Intraday::NATIVE_RESOLUTIONS[0] === '1', 'finest resolution first');

// A pinned resolution is tried first, so a known-good feed costs one request.
$gc_pref = GC_TGJU_Intraday::resolutions_from_setting('10m');
gc_check($gc_pref[0] === '10m', 'a pinned resolution is tried first');
gc_check(count($gc_pref) === count(GC_TGJU_Intraday::NATIVE_RESOLUTIONS),
    'pinning reorders rather than narrowing, so a changed feed still recovers');
gc_check(!in_array('10m', array_slice($gc_pref, 1), true),
    'the pinned resolution is not duplicated later in the list');
gc_check(GC_TGJU_Intraday::resolutions_from_setting('') === GC_TGJU_Intraday::NATIVE_RESOLUTIONS,
    'no pin means the default order');
gc_check(GC_TGJU_Intraday::resolutions_from_setting('nonsense') === GC_TGJU_Intraday::NATIVE_RESOLUTIONS,
    'an unknown pin is ignored');

gc_reset_remote();
$gc_asked = array();
gc_test_stub_remote_get('tgju.org', function ($url) use (&$gc_asked) {
    $gc_asked[] = $url;
    $start = (int) explode('&', explode('from=', $url)[1])[0];
    $times = array();
    for ($i = 1; $i <= 5; $i++) { $times[] = $start + ($i * 600); }
    return gc_udf(array('s' => 'ok', 't' => $times, 'c' => array_fill(0, 5, 70000000)));
});
GC_TGJU_Intraday::fetch_candles(
    $gold, $gc_midnight, $gc_midnight + 3600, null,
    GC_TGJU_Intraday::resolutions_from_setting('10m')
);
gc_check(count($gc_asked) === 1, 'a pinned resolution that works costs a single request');
gc_check(strpos($gc_asked[0], 'resolution=10m') !== false,
    'that single request used the pinned resolution');

// The probe must call daily data a failure, and say why.
gc_reset_remote();
gc_test_stub_remote_get('tgju.org', function ($url) {
    $start = (int) explode('&', explode('from=', $url)[1])[0];
    $stop = (int) explode('&', explode('to=', $url)[1])[0];
    $first = (int) (ceil($start / 86400) * 86400);
    $times = array();
    for ($ts = $first; $ts <= $stop; $ts += 86400) { $times[] = $ts; }
    return gc_udf(array('s' => 'ok', 't' => $times,
        'c' => array_fill(0, max(1, count($times)), 70000000)));
});
$gc_report = GC_TGJU_Intraday::probe($gold);
$gc_any_ok = false;
$gc_daily_row = null;
foreach ($gc_report as $row) {
    if (!empty($row['ok'])) { $gc_any_ok = true; }
    if ($gc_daily_row === null && !empty($row['candles'])) { $gc_daily_row = $row; }
}
gc_check($gc_any_ok === false, 'the probe does not call a daily feed working');
gc_check($gc_daily_row !== null, 'the probe recorded the daily rows it saw');
gc_check($gc_daily_row['intraday'] === false, 'the probe marks those rows as not intraday');
gc_check($gc_daily_row['spacing_seconds'] === 86400, 'the probe reports the measured spacing');
gc_check(strpos($gc_daily_row['error'], 'روزانه') !== false, 'the probe says why it failed');

gc_reset_remote();

// -- aggregate_candles: bars keep their own extremes ------------------------

// 1404/05/28 == 2025-08-19; 06:00 UTC == 09:30 Tehran.
$base = gmmktime(6, 0, 0, 8, 19, 2025);
$bars = array(
    array('ts' => $base, 'open' => 100, 'high' => 140, 'low' => 95, 'close' => 110),
    array('ts' => $base + 60, 'open' => 110, 'high' => 180, 'low' => 90, 'close' => 120),
    array('ts' => $base + 120, 'open' => 120, 'high' => 130, 'low' => 105, 'close' => 115),
);

gc_check(GC_Intraday::aggregate_candles(array(), '10m') === array(),
    'aggregating no bars yields no rows');

$row = GC_Intraday::aggregate_candles($bars, '10m')[0];
gc_check($row['open'] === 100, 'the bucket open is the first bar open');
gc_check($row['high'] === 180, 'the bucket high is the max of bar highs, not of closes');
gc_check($row['low'] === 90, 'the bucket low is the min of bar lows, not of closes');
gc_check($row['close'] === 115, 'the bucket close is the last bar close');
gc_check($row['samples'] === 3, 'the sample count is the number of source bars');
gc_check($row['average'] === (int) round((110 + 120 + 115) / 3),
    'the average is the mean of the bar closes');
gc_check($row['time'] === '09:30', 'bars are bucketed on the Tehran clock');

$hourly = GC_Intraday::aggregate_candles($bars, '1h');
gc_check(count($hourly) === 1 && $hourly[0]['samples'] === 3,
    'hourly merges every bucket of the hour');

$reversed = GC_Intraday::aggregate_candles(array_reverse($bars), '10m')[0];
gc_check($reversed['open'] === 100 && $reversed['close'] === 115,
    'out-of-order bars are sorted before bucketing');

$closeonly = GC_Intraday::aggregate_candles(
    array(array('ts' => $base, 'open' => null, 'high' => null, 'low' => null, 'close' => 250)), '10m'
)[0];
gc_check(
    $closeonly['open'] === 250 && $closeonly['high'] === 250 && $closeonly['low'] === 250,
    'a bar with only a close falls back to it for open/high/low'
);

gc_check(
    GC_Intraday::aggregate_candles(
        array(array('ts' => $base, 'open' => null, 'high' => 1, 'low' => 1, 'close' => null)), '10m'
    ) === array(),
    'a bar with no open and no close is skipped'
);

$ticks = GC_Intraday::aggregate_candles(array(
    array('ts' => $base, 'open' => 100, 'high' => 100, 'low' => 100, 'close' => 100),
    array('ts' => $base + 60, 'open' => 100, 'high' => 100, 'low' => 100, 'close' => 100),
    array('ts' => $base + 120, 'open' => 100, 'high' => 100, 'low' => 100, 'close' => 130),
), 'tick');
gc_check(array_column($ticks, 'close') === array(100, 130),
    'tick keeps one row per changed close');
gc_check($ticks[1]['change'] === 30, 'tick rows carry the change from the previous kept row');

gc_check(GC_Intraday::aggregate_candles($bars, 'daily') === array(),
    'a daily resolution is not aggregated from bars here');

$precise = GC_Intraday::aggregate_candles(
    array(array('ts' => $base, 'open' => 3301.111, 'high' => 3302.888,
                'low' => 3300.222, 'close' => 3301.555)), '10m', 2
)[0];
gc_check($precise['high'] === 3302.89 && $precise['low'] === 3300.22,
    'decimals are respected when rounding bar extremes');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
