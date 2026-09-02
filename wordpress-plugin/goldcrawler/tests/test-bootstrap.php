<?php
/**
 * Boots the real goldcrawler.php (not individually-required classes) to
 * catch wiring mistakes: missing requires, wrong constant names, hooks
 * registered under the wrong name, etc.
 */

require __DIR__ . '/wp-stubs.php';
define('GC_STANDALONE_TEST', true);
$GLOBALS['gc_test_is_admin'] = true; // simulate a wp-admin page load, like the real access-settings page

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

// -- shortcode is registered and gated by GC_License, not a blanket rule ---
gc_check(isset($GLOBALS['gc_test_actions']['shortcode_gold_crawler']), '[gold_crawler] shortcode is registered');

$GLOBALS['gc_test_user_role'] = 'logged_out';
$anon_html = call_user_func($GLOBALS['gc_test_actions']['shortcode_gold_crawler'][0]);
gc_check(strpos($anon_html, 'وارد حساب کاربری') !== false, 'a logged-out visitor is told to sign in');
gc_check(strpos($anon_html, 'class="goldcrawler-app goldcrawler-app--gate"') !== false, 'the logged-out gate is still scoped/styled as a goldcrawler-app');
gc_check(strpos($anon_html, 'href="' . GC_Shortcode::DEFAULT_ACCOUNT_URL . '"') !== false, 'the logged-out gate links straight to the site login/register page');
gc_check(strpos($anon_html, 'gate__icon--lock') !== false, 'the logged-out gate uses the lock icon variant');

add_filter('goldcrawler_account_url', function () { return 'https://example.test/custom-login/'; });
$anon_html_filtered = call_user_func($GLOBALS['gc_test_actions']['shortcode_gold_crawler'][0]);
gc_check(strpos($anon_html_filtered, 'href="https://example.test/custom-login/"') !== false, 'goldcrawler_account_url filter overrides the login link');

$GLOBALS['gc_test_user_role'] = 'subscriber';
$GLOBALS['gc_test_current_user_id'] = 4242; // not licensed
$unlicensed_html = call_user_func($GLOBALS['gc_test_actions']['shortcode_gold_crawler'][0]);
gc_check(strpos($unlicensed_html, 'مجوز استفاده از این ابزار') !== false, 'a signed-in but unlicensed user is told to contact the admin, not asked to log in again');
gc_check(strpos($unlicensed_html, 'gate__icon--warn') !== false, 'the unlicensed gate uses the warn icon variant, not the lock one');
gc_check(strpos($unlicensed_html, 'gate__cta') === false, 'the unlicensed gate has no login CTA - the user is already logged in');

GC_License::grant(4242);
$app_html = call_user_func($GLOBALS['gc_test_actions']['shortcode_gold_crawler'][0]);
gc_check(strpos($app_html, 'id="goldcrawler-app"') !== false, 'a licensed Subscriber sees the real app container');
gc_check(strpos($app_html, 'class="goldcrawler-app"') !== false, 'the app root carries its scoping class');
GC_License::revoke(4242);

$GLOBALS['gc_test_user_role'] = 'administrator';
$admin_html = call_user_func($GLOBALS['gc_test_actions']['shortcode_gold_crawler'][0]);
gc_check(strpos($admin_html, 'id="goldcrawler-app"') !== false, 'an Administrator sees the app without needing an explicit grant');
$GLOBALS['gc_test_user_role'] = null;

// -- AJAX actions are registered under the expected hook names --------------
foreach (array('meta', 'archive', 'series', 'export', 'settings', 'symbols', 'crawl') as $action) {
    gc_check(isset($GLOBALS['gc_test_actions']["wp_ajax_goldcrawler_{$action}"]), "wp_ajax_goldcrawler_{$action} is registered");
}
gc_check(!isset($GLOBALS['gc_test_actions']['wp_ajax_nopriv_goldcrawler_meta']), 'no nopriv handler exists (logged-out visitors get nothing)');

// -- the access-settings admin page is wired up on a real wp-admin load ----
gc_check(isset($GLOBALS['gc_test_actions']['admin_menu']), 'GC_Admin registers on admin_menu when is_admin() is true');
foreach ($GLOBALS['gc_test_actions']['admin_menu'] as $cb) { call_user_func($cb); }
gc_check(!empty($GLOBALS['gc_test_actions']['options_pages']), 'the GoldCrawler settings page is actually added under Settings');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
