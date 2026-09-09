<?php
/** Functional tests for GC_Storage using the WP function stubs. */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-gc-storage.php';

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

// -- settings ---------------------------------------------------------
$settings = GC_Storage::get_settings();
gc_check(in_array('geram18', $settings['symbols'], true), 'default settings include geram18');
gc_check($settings['auto_crawl'] === false, 'default auto_crawl is off (no unattended daily fetching unless opted in)');

$updated = GC_Storage::update_settings(array('theme' => 'dark', 'unknown_key' => 'x'));
gc_check($updated['theme'] === 'dark', 'settings update persists known keys');
gc_check(!array_key_exists('unknown_key', $updated), 'unknown keys are dropped');

$reloaded = GC_Storage::get_settings();
gc_check($reloaded['theme'] === 'dark', 'settings survive a fresh read (real file I/O)');

// -- cache --------------------------------------------------------------
list($points, $fetched_at) = GC_Storage::read_cache('geram18');
gc_check($points === null, 'cache starts empty');

GC_Storage::write_cache('geram18', array('1404/01/01' => array('close' => 1)));
list($points2, $fetched_at2) = GC_Storage::read_cache('geram18');
gc_check($points2['1404/01/01']['close'] === 1, 'cache round-trips a value');
gc_check($fetched_at2 > 0, 'cache records a fetch timestamp');

// -- archive --------------------------------------------------------------
$added1 = GC_Storage::merge_archive('geram18', array(
    '1404/01/01' => array('date' => '1404/01/01', 'close' => 1),
    '1404/01/02' => array('date' => '1404/01/02', 'close' => 2),
));
gc_check($added1 === 2, 'merge_archive counts new days');

$added2 = GC_Storage::merge_archive('geram18', array('1404/01/01' => array('date' => '1404/01/01', 'close' => 9)));
gc_check($added2 === 0, 'merging an existing day adds nothing new');
$archive = GC_Storage::load_archive('geram18');
gc_check($archive['1404/01/01']['close'] === 9, 'merge overwrites an existing day with the new value');

// custom symbol key containing a dot must round-trip through the archive filename.
GC_Storage::merge_archive('my.custom.symbol', array('1404/01/01' => array('date' => '1404/01/01', 'close' => 5)));
$summary = GC_Storage::archive_summary();
$keys = array_column($summary, 'key');
gc_check(in_array('my.custom.symbol', $keys, true), 'dotted custom-symbol key round-trips exactly in the archive');

// path traversal attempt must not escape the archive directory.
GC_Storage::merge_archive('../../evil', array('1404/01/01' => array('date' => '1404/01/01', 'close' => 1)));
$files = glob(GC_Storage::base_dir() . '/archive/*.json');
foreach ($files as $f) {
    gc_check(strpos(basename($f), '..') === false, "no path-traversal filename escaped: {$f}");
}


// -- admin-only settings ----------------------------------------------------
// These decide what the site's own scheduler does, where data comes from and
// how long it is kept. A licensed front-end user saving their own report
// preferences must not be able to reach them.

gc_check(GC_Storage::is_admin_only('intraday_recording'), 'recording is admin-only');
gc_check(GC_Storage::is_admin_only('sampler_symbols'), 'the sampler symbol list is admin-only');
gc_check(GC_Storage::is_admin_only('retention_days'), 'retention is admin-only');
gc_check(GC_Storage::is_admin_only('intraday_endpoint'), 'the chart endpoint is admin-only');
gc_check(GC_Storage::is_admin_only('disabled_symbols'), 'the site symbol list is admin-only');
gc_check(!GC_Storage::is_admin_only('symbols'), 'a user\'s own symbol selection is not admin-only');
gc_check(!GC_Storage::is_admin_only('resolution'), 'a user\'s own resolution is not admin-only');
gc_check(!GC_Storage::is_admin_only('theme'), 'a user\'s own theme is not admin-only');

GC_Storage::update_settings(array(
    'intraday_recording' => true,
    'retention_days' => 45,
    'sampler_symbols' => array('geram18'),
), true);
$gc_before = GC_Storage::get_settings();
gc_check($gc_before['retention_days'] === 45, 'an admin can set retention');

// A non-admin save must drop the admin-only keys and keep its own.
$gc_after = GC_Storage::update_settings(array(
    'retention_days' => 9999,
    'intraday_recording' => false,
    'sampler_symbols' => array('sekee', 'nim'),
    'intraday_endpoint' => 'https://evil.example/?symbol={symbol}',
    'theme' => 'dark',
    'resolution' => '10m',
), false);
gc_check($gc_after['retention_days'] === 45, 'a non-admin cannot change retention');
gc_check($gc_after['intraday_recording'] === true, 'a non-admin cannot switch recording off');
gc_check($gc_after['sampler_symbols'] === array('geram18'), 'a non-admin cannot change what the scheduler records');
gc_check($gc_after['intraday_endpoint'] !== 'https://evil.example/?symbol={symbol}', 'a non-admin cannot repoint the data source');
gc_check($gc_after['theme'] === 'dark', 'a non-admin still saves their own theme');
gc_check($gc_after['resolution'] === '10m', 'a non-admin still saves their own resolution');

// Retention is clamped, so a bad value can never delete today's own samples
// or grow without bound.
GC_Storage::update_settings(array('retention_days' => 0), true);
gc_check(GC_Storage::retention_days() === 1, 'retention below 1 clamps to 1 day');
GC_Storage::update_settings(array('retention_days' => -5), true);
gc_check(GC_Storage::retention_days() === 1, 'a negative retention clamps to 1 day');
GC_Storage::update_settings(array('retention_days' => 99999), true);
gc_check(GC_Storage::retention_days() === 3650, 'a huge retention clamps to ten years');
GC_Storage::update_settings(array('retention_days' => 30), true);
gc_check(GC_Storage::retention_days() === 30, 'a sane retention passes through');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
