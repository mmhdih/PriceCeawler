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
gc_check($settings['auto_crawl'] === true, 'default auto_crawl is true');

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

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
