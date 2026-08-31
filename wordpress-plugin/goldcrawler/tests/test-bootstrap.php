<?php
/**
 * Boots the real goldcrawler.php (not individually-required classes) to
 * catch wiring mistakes: missing requires, wrong constant names, hooks
 * registered under the wrong name, etc.
 */

require __DIR__ . '/wp-stubs.php';
define('GC_STANDALONE_TEST', true);

require dirname(__DIR__) . '/goldcrawler.php';
do_action('init'); // the plugin registers everything from here

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

// -- activation/deactivation wires WP-Cron correctly -----------------------
foreach ($GLOBALS['gc_test_actions']['activate'] as $cb) { call_user_func($cb); }
gc_check(wp_next_scheduled(GC_Cron::HOOK) !== false, 'activation schedules the daily crawl cron hook');

foreach ($GLOBALS['gc_test_actions']['deactivate'] as $cb) { call_user_func($cb); }
gc_check(wp_next_scheduled(GC_Cron::HOOK) === false, 'deactivation clears the scheduled cron hook');

// re-activate for the rest of this test run
foreach ($GLOBALS['gc_test_actions']['activate'] as $cb) { call_user_func($cb); }

// -- the cron hook actually calls into the crawler --------------------------
gc_test_stub_remote_get('geram18', array('code' => 200, 'body' => json_encode(array('data' => array(
    array('7,000,000', '6,990,000', '7,010,000', '7,000,000', '', '', '2025-08-19', '1404/05/28'),
)))));
GC_Storage::update_settings(array('symbols' => array('geram18'), 'auto_crawl' => true, 'last_crawl' => ''));
do_action(GC_Cron::HOOK);
gc_check(count(GC_Storage::load_archive('geram18')) > 0, 'the cron hook performed a real crawl into the archive');

// -- shortcode is registered and gated by capability -----------------------
gc_check(isset($GLOBALS['gc_test_actions']['shortcode_gold_crawler']), '[gold_crawler] shortcode is registered');

$GLOBALS['gc_test_current_user_can'] = false;
$locked_html = call_user_func($GLOBALS['gc_test_actions']['shortcode_gold_crawler'][0]);
gc_check(strpos($locked_html, 'در دسترس نیست') !== false || strpos($locked_html, 'مدیران سایت') !== false, 'non-admins see a locked message, not the app shell');

$GLOBALS['gc_test_current_user_can'] = true;
$app_html = call_user_func($GLOBALS['gc_test_actions']['shortcode_gold_crawler'][0]);
gc_check(strpos($app_html, 'id="goldcrawler-app"') !== false, 'admins see the real app container');
gc_check(strpos($app_html, 'class="goldcrawler-app"') !== false, 'the app root carries its scoping class');

// -- AJAX actions are registered under the expected hook names --------------
foreach (array('meta', 'archive', 'series', 'export', 'settings', 'symbols', 'crawl') as $action) {
    gc_check(isset($GLOBALS['gc_test_actions']["wp_ajax_goldcrawler_{$action}"]), "wp_ajax_goldcrawler_{$action} is registered");
}
gc_check(!isset($GLOBALS['gc_test_actions']['wp_ajax_nopriv_goldcrawler_meta']), 'no nopriv handler exists (logged-out visitors get nothing)');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
