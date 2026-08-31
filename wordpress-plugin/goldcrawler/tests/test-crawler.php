<?php
/** Functional tests for GC_Crawler using stubbed wp_remote_get responses. */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-gc-jalali.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-tgju.php';
require __DIR__ . '/../includes/class-gc-report.php';
require __DIR__ . '/../includes/class-gc-storage.php';
require __DIR__ . '/../includes/class-gc-crawler.php';

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

function gc_ok_response($rows) {
    return array('code' => 200, 'body' => json_encode(array('data' => $rows)));
}

// -- resolve() ------------------------------------------------------------
$resolved = GC_Crawler::resolve(array('geram18', 'geram18', '  ', 'sekee', 'unknown_key'));
gc_check(array_map(fn($s) => $s['key'], $resolved) === array('geram18', 'sekee', 'unknown_key'), 'resolve dedupes/drops blanks without shifting');
gc_check($resolved[2]['custom'] === true, 'unknown key falls back to a custom symbol');

GC_Storage::update_settings(array('custom_symbols' => array(array('key' => 'my_gold', 'name' => 'طلای من', 'currency' => 'USD'))));
$resolved2 = GC_Crawler::resolve(array('my_gold'));
gc_check($resolved2[0]['name'] === 'طلای من', 'a user-defined custom symbol wins over the bare fallback');
gc_check($resolved2[0]['currency'] === 'USD', 'custom symbol currency is respected');

// -- build() with a stubbed TGJU response --------------------------------
gc_test_stub_remote_get('geram18', gc_ok_response(array(
    array('7,000,000', '6,990,000', '7,010,000', '7,000,000', '', '', '2025-08-19', '1404/05/28'),
    array('7,050,000', '7,040,000', '7,060,000', '7,050,000', '', '', '2025-08-20', '1404/05/29'),
)));
gc_test_stub_remote_get('sekee', gc_ok_response(array())); // empty payload -> GC_Tgju_Exception

$result = GC_Crawler::build(array('geram18', 'sekee'), array(1404, 5, 28), array(1404, 5, 29));
gc_check(count($result['series']) === 1, 'only the successful symbol produces a series');
gc_check($result['series'][0]['symbol']['key'] === 'geram18', 'the successful series is for geram18');
gc_check($result['errors'][0]['symbol'] === 'sekee', 'the failing symbol is reported with its key');
gc_check(strpos($result['errors'][0]['message'], 'sekee') !== false, 'error message names the symbol');

// -- caching ----------------------------------------------------------------
$fetch_count_before = 0;
gc_test_stub_remote_get('price_dollar_rl', function () use (&$fetch_count_before) {
    $fetch_count_before++;
    return gc_ok_response(array(array('1', '118000', '119000', '118500', '', '', '2025-08-20', '1404/05/29')));
});
$symbol = GC_Crawler::resolve(array('price_dollar_rl'))[0];

list(, $cached1) = GC_Crawler::points_for($symbol);
list(, $cached2) = GC_Crawler::points_for($symbol);
gc_check($fetch_count_before === 1, 'second call within the cache TTL does not refetch');
gc_check($cached1 === false && $cached2 === true, 'first call is live, second is served from cache');

list(, $cached3) = GC_Crawler::points_for($symbol, true);
gc_check($fetch_count_before === 2, 'force=true bypasses the cache');

// -- build() feeds the archive ----------------------------------------------
$archived = GC_Storage::load_archive('geram18');
gc_check(count($archived) === 2, 'build() merged fetched points into the archive');

// -- daily_crawl() ------------------------------------------------------------
$daily = GC_Crawler::daily_crawl(array('geram18'));
gc_check(isset($daily['added']['geram18']), 'daily_crawl reports the crawled symbol');
gc_check($daily['date'] !== '', 'daily_crawl reports a Jalali date');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
