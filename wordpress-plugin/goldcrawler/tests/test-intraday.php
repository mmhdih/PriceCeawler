<?php
/**
 * Tests for GC_Intraday: Tehran-local bucketing, the three resolutions,
 * storage round-trips and retention pruning.
 */

require __DIR__ . '/wp-stubs.php';
define('GC_STANDALONE_TEST', true);
require __DIR__ . '/../includes/class-gc-jalali.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-report.php';
require __DIR__ . '/../includes/class-gc-storage.php';
require __DIR__ . '/../includes/class-gc-intraday.php';

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

$toman = array('key' => 'geram18', 'name' => 'طلای ۱۸ عیار', 'group' => 'طلا و نقره',
    'currency' => 'IRR', 'decimals' => 0);
$dollar = array('key' => 'ons', 'name' => 'انس طلا', 'group' => 'طلا و نقره',
    'currency' => 'USD', 'decimals' => 2);

// 2025-08-19 06:00:00 UTC == 09:30 Tehran (UTC+3:30)
$base = gmmktime(6, 0, 0, 8, 19, 2025);

// -- Tehran-local clock ------------------------------------------------------
gc_check(GC_Intraday::local_time($base) === '09:30', 'a UTC timestamp renders in Tehran local time (+03:30)');
gc_check(GC_Intraday::local_iso_date($base) === '2025-08-19', 'the local ISO day is derived from Tehran time');
gc_check(GC_Intraday::local_jalali_date($base) === '1404/05/28', 'the local Jalali day is derived from Tehran time');

// A UTC instant that is already "tomorrow" in Tehran must land on the next day.
$late = gmmktime(21, 0, 0, 8, 19, 2025); // 00:30 Tehran on the 20th
gc_check(GC_Intraday::local_iso_date($late) === '2025-08-20', 'an evening UTC instant that is past midnight in Tehran rolls to the next local day');
gc_check(GC_Intraday::local_time($late) === '00:30', 'the same instant renders as 00:30 local');

// -- bucket alignment --------------------------------------------------------
// Hour buckets must start on the local hour, which is :30 past a UTC hour.
$in_hour = gmmktime(6, 47, 12, 8, 19, 2025); // 10:17:12 Tehran
gc_check(GC_Intraday::local_time(GC_Intraday::bucket_start($in_hour, '1h')) === '10:00', 'hourly buckets start on the Tehran hour, not the UTC hour');
gc_check(GC_Intraday::local_time(GC_Intraday::bucket_start($in_hour, '10m')) === '10:10', '10-minute buckets align to the local 10-minute mark');
gc_check(GC_Intraday::bucket_start($in_hour, 'tick') === $in_hour, 'tick resolution has no bucket - the timestamp is kept as-is');

// -- 10-minute aggregation ---------------------------------------------------
// Three samples inside one 10-minute window, then one in the next.
$samples = array(
    $base + 60 => 7000000.0,   // 09:31
    $base + 120 => 7020000.0,  // 09:32
    $base + 180 => 6990000.0,  // 09:33
    $base + 700 => 7050000.0,  // 09:41 -> next bucket
);
$rows = GC_Intraday::aggregate($samples, '10m', 0);
gc_check(count($rows) === 2, '10m aggregation groups samples into one row per 10-minute bucket');
gc_check($rows[0]['time'] === '09:30' && $rows[1]['time'] === '09:40', 'bucket rows are labelled with the bucket start time');
gc_check($rows[0]['open'] === 7000000, 'the bucket open is the first sample in the window');
gc_check($rows[0]['close'] === 6990000, 'the bucket close is the last sample in the window');
gc_check($rows[0]['low'] === 6990000 && $rows[0]['high'] === 7020000, 'the bucket low/high span every sample in the window');
gc_check($rows[0]['average'] === (int) round((7000000 + 7020000 + 6990000) / 3), 'the bucket average is the mean of its actual samples');
gc_check($rows[0]['samples'] === 3 && $rows[1]['samples'] === 1, 'each row reports how many samples it was built from');
gc_check($rows[0]['date'] === '1404/05/28', 'intraday rows carry the Jalali date alongside the time');

// Order of the input must not matter.
$shuffled = array_reverse($samples, true);
$rows_shuffled = GC_Intraday::aggregate($shuffled, '10m', 0);
gc_check($rows_shuffled == $rows, 'aggregation sorts by timestamp, so unordered input gives the same rows');

// -- hourly aggregation ------------------------------------------------------
$hour_rows = GC_Intraday::aggregate($samples, '1h', 0);
gc_check(count($hour_rows) === 1, 'the same samples collapse into a single hourly row');
gc_check($hour_rows[0]['time'] === '09:00', 'the hourly row is labelled with the local hour');
gc_check($hour_rows[0]['open'] === 7000000 && $hour_rows[0]['close'] === 7050000, 'the hourly row spans the whole hour: first open, last close');
gc_check($hour_rows[0]['samples'] === 4, 'the hourly row counts all four samples');

// An empty 10 minutes is skipped, not carried forward - no observation is not a flat price.
$sparse = array($base => 100.0, $base + 3000 => 110.0); // 09:30 and 10:20
$sparse_rows = GC_Intraday::aggregate($sparse, '10m', 0);
gc_check(count($sparse_rows) === 2, 'unsampled 10-minute windows are skipped rather than invented');

// -- tick ("every change") aggregation ---------------------------------------
$ticks = array(
    $base => 7000000.0,
    $base + 60 => 7000000.0,   // unchanged -> dropped
    $base + 120 => 7010000.0,  // changed
    $base + 180 => 7010000.0,  // unchanged -> dropped
    $base + 240 => 6990000.0,  // changed
);
$tick_rows = GC_Intraday::aggregate($ticks, 'tick', 0);
gc_check(count($tick_rows) === 3, 'tick aggregation keeps only the samples where the price actually changed');
gc_check($tick_rows[0]['change'] === null, 'the first tick has no previous price to compare against');
gc_check($tick_rows[1]['change'] === 10000, 'each later tick reports the change from the previous kept price');
gc_check($tick_rows[2]['change'] === -20000, 'a downward change is reported as a negative number');
gc_check($tick_rows[1]['time'] === '09:32', 'tick rows are labelled with the exact time of the change');
gc_check(
    $tick_rows[0]['open'] === $tick_rows[0]['close'] && $tick_rows[0]['low'] === $tick_rows[0]['high'],
    'a tick row is a single observation, so its OHLC are all the same price'
);

// Rounding decides what counts as "changed": sub-display-precision noise is not a change.
$noise = array($base => 1234.567, $base + 60 => 1234.569, $base + 120 => 1234.99);
gc_check(count(GC_Intraday::aggregate($noise, 'tick', 2)) === 2, 'a change smaller than the displayed precision is not counted as a change');

// -- resolution helpers ------------------------------------------------------
gc_check(GC_Intraday::is_intraday('10m') && GC_Intraday::is_intraday('1h') && GC_Intraday::is_intraday('tick'), 'the three intraday resolutions are recognised');
gc_check(!GC_Intraday::is_intraday('daily'), 'daily is not an intraday resolution');
gc_check(GC_Intraday::normalise_resolution('nonsense') === 'daily', 'an unknown resolution falls back to daily');
gc_check(GC_Intraday::normalise_resolution('1h') === '1h', 'a valid resolution passes through normalisation');
gc_check(GC_Intraday::aggregate(array(), '10m') === array(), 'aggregating nothing yields no rows (no crash)');

// -- storage round-trip ------------------------------------------------------
gc_check(GC_Intraday::record('geram18', 7000000, $base) === true, 'a sample is recorded');
gc_check(GC_Intraday::record('geram18', 7001000, $base + 60) === true, 'a second sample at a different second is recorded');
gc_check(GC_Intraday::record('geram18', 7002000, $base) === false, 'a sample for a second already recorded is rejected (a double-fired cron is harmless)');
gc_check(GC_Intraday::record('geram18', 0, $base + 120) === false, 'a zero/invalid price is never recorded');
// NAN/INF pass is_numeric() and a `<= 0` test, but json_encode refuses them:
// the encode would return false and the day file would be truncated, losing
// every earlier sample for that day.
gc_check(GC_Intraday::record('geram18', NAN, $base + 130) === false, 'a NAN price is rejected before it can destroy the day file');
gc_check(GC_Intraday::record('geram18', INF, $base + 140) === false, 'an INF price is rejected');
gc_check(count(GC_Intraday::load_samples('geram18', $base - 10, $base + 3600)) === 2, 'the rejected non-finite prices left the earlier samples intact');

$loaded = GC_Intraday::load_samples('geram18', $base - 10, $base + 3600);
gc_check(count($loaded) === 2, 'both stored samples load back');
gc_check($loaded[$base] === 7000000.0 && $loaded[$base + 60] === 7001000.0, 'stored prices round-trip exactly');
gc_check(GC_Intraday::load_samples('geram18', $base + 30, $base + 40) === array(), 'loading a window with no samples returns nothing');

// A symbol key with dots/dashes must not collide or break the path.
gc_check(GC_Intraday::record('crypto-bitcoin', 61234.5, $base) === true, 'a symbol key with a dash is stored fine');
gc_check(count(GC_Intraday::load_samples('crypto-bitcoin', $base - 10, $base + 10)) === 1, 'the dashed symbol loads back independently of the other symbol');
gc_check(count(GC_Intraday::load_samples('geram18', $base - 10, $base + 10)) === 1, 'symbols do not bleed into each other');

// Samples spanning two local days must both load.
gc_check(GC_Intraday::record('geram18', 7100000, $late) === true, 'a sample on the next local day is recorded');
$across = GC_Intraday::load_samples('geram18', $base - 10, $late + 10);
gc_check(count($across) === 3, 'a range spanning two local days loads samples from both day files');

// -- build_series over a Jalali range ---------------------------------------
// Stored: 7000000 @09:30, 7001000 @09:31 (same 10m bucket), 7100000 @00:30 next day.
$series = GC_Intraday::build_series($toman, array(1404, 5, 28), array(1404, 5, 29), '10m');
gc_check(count($series['rows']) === 2, 'build_series buckets the two same-window samples together and keeps the next day separate');
gc_check($series['stats']['first'] === 7001000 && $series['stats']['last'] === 7100000, 'stats first/last come from the first and last row close');
gc_check($series['stats']['change'] === 99000, 'stats report the change across the range');
gc_check($series['stats']['unit'] === 'تومان', 'stats carry the display unit');
gc_check($series['stats']['trading_days'] === 2, 'stats count the rows that actually have a close');

// The same range at tick resolution keeps all three, since every price differed.
$tick_series = GC_Intraday::build_series($toman, array(1404, 5, 28), array(1404, 5, 29), 'tick');
gc_check(count($tick_series['rows']) === 3, 'the same range at tick resolution keeps every distinct price');

$empty_series = GC_Intraday::build_series($toman, array(1399, 1, 1), array(1399, 1, 2), '10m');
gc_check($empty_series['rows'] === array(), 'a range with no recorded samples yields no rows');
gc_check($empty_series['stats']['first'] === null, 'stats on an empty range are null rather than a crash');

// USD symbols keep their decimals.
GC_Intraday::record('ons', 2401.567, $base);
GC_Intraday::record('ons', 2402.129, $base + 60);
$usd_series = GC_Intraday::build_series($dollar, array(1404, 5, 28), array(1404, 5, 28), '10m');
gc_check($usd_series['rows'][0]['high'] === 2402.13, 'a dollar symbol keeps two decimals in its intraday rows');
gc_check($usd_series['stats']['unit'] === 'دلار', 'a dollar symbol reports the dollar unit');

// -- summary -----------------------------------------------------------------
$summary = GC_Intraday::summary();
$by_key = array();
foreach ($summary as $row) { $by_key[$row['key']] = $row; }
gc_check(isset($by_key['geram18']) && $by_key['geram18']['samples'] === 3, 'the summary reports the stored sample count per symbol');
gc_check($by_key['geram18']['days'] === 2, 'the summary reports how many day files a symbol has');
gc_check($by_key['geram18']['first'] === '2025-08-19' && $by_key['geram18']['last'] === '2025-08-20', 'the summary reports the first and last recorded day');

// -- retention pruning -------------------------------------------------------
$old = $base - (40 * 86400); // 40 days before the samples above
GC_Intraday::record('geram18', 6000000, $old);
gc_check(count(GC_Intraday::load_samples('geram18', $old - 10, $old + 10)) === 1, 'an old sample is stored before pruning');

// Prune relative to $base: the 40-day-old file is past the 30-day window.
$removed = GC_Intraday::prune($base);
gc_check($removed >= 1, 'pruning removes day files older than the retention window');
gc_check(GC_Intraday::load_samples('geram18', $old - 10, $old + 10) === array(), 'the pruned day is gone');
gc_check(count(GC_Intraday::load_samples('geram18', $base - 10, $base + 10)) === 1, 'pruning leaves the in-window days untouched');


// -- retention is an admin setting, not a constant --------------------------
// The window is configurable, so pruning must follow the setting rather than
// the class constant it defaults to.
$gc_now = gmmktime(12, 0, 0, 8, 19, 2025);
GC_Intraday::record('retention_test', 100, $gc_now);                 // today
GC_Intraday::record('retention_test', 101, $gc_now - (5 * 86400));   // 5 days old
GC_Intraday::record('retention_test', 102, $gc_now - (20 * 86400));  // 20 days old

GC_Storage::update_settings(array('retention_days' => 7), true);
GC_Intraday::prune($gc_now);
$gc_left = GC_Intraday::load_samples('retention_test', $gc_now - (60 * 86400), $gc_now + 60);
gc_check(count($gc_left) === 2, 'a 7-day retention keeps today and the 5-day-old sample');

GC_Storage::update_settings(array('retention_days' => 3), true);
GC_Intraday::prune($gc_now);
$gc_left = GC_Intraday::load_samples('retention_test', $gc_now - (60 * 86400), $gc_now + 60);
gc_check(count($gc_left) === 1, 'shortening retention to 3 days prunes the 5-day-old sample');
gc_check(isset($gc_left[$gc_now]), "today's own sample survives the shortest retention");

// An explicit override wins over the setting (tests and one-off cleanups).
GC_Intraday::record('retention_test', 103, $gc_now - (2 * 86400));
GC_Intraday::prune($gc_now, 1);
$gc_left = GC_Intraday::load_samples('retention_test', $gc_now - (60 * 86400), $gc_now + 60);
gc_check(count($gc_left) === 1, 'an explicit retention argument overrides the setting');

GC_Storage::update_settings(array('retention_days' => 30), true);

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
