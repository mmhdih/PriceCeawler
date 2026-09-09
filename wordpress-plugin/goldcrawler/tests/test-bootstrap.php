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
$GLOBALS['gc_test_options']['users_can_register'] = true; // site allows self-registration, like tavoosweb.ir does
$anon_html = call_user_func($GLOBALS['gc_test_actions']['shortcode_gold_crawler'][0]);
gc_check(strpos($anon_html, 'وارد حساب کاربری') !== false, 'a logged-out visitor is told to sign in');
gc_check(strpos($anon_html, 'class="goldcrawler-app goldcrawler-app--gate"') !== false, 'the logged-out gate is still scoped/styled as a goldcrawler-app');
gc_check(strpos($anon_html, 'gate__icon--lock') !== false, 'the logged-out gate uses the lock icon variant');
gc_check(strpos($anon_html, 'id="goldcrawlerLoginForm"') !== false, 'the logged-out gate renders an inline login form (no redirect to another page)');
gc_check(strpos($anon_html, 'id="goldcrawlerRegisterForm"') !== false, 'the logged-out gate also renders an inline registration form');
gc_check(strpos($anon_html, 'data-form="register" hidden') !== false, 'the registration form starts hidden - the login tab is active by default');
gc_check(in_array('goldcrawler-gate', $GLOBALS['gc_test_enqueued_scripts'], true), 'gate.js (the inline auth handler) is enqueued for the logged-out gate');

$GLOBALS['gc_test_options']['users_can_register'] = false;
$no_registration_html = call_user_func($GLOBALS['gc_test_actions']['shortcode_gold_crawler'][0]);
gc_check(strpos($no_registration_html, 'id="goldcrawlerRegisterForm"') === false, 'no registration form/tab is offered when the site does not allow self-registration');
gc_check(strpos($no_registration_html, 'id="goldcrawlerLoginForm"') !== false, 'the login form is still offered either way');
$GLOBALS['gc_test_options']['users_can_register'] = true;

$GLOBALS['gc_test_user_role'] = 'subscriber';
$GLOBALS['gc_test_current_user_id'] = 4242; // not licensed
$unlicensed_html = call_user_func($GLOBALS['gc_test_actions']['shortcode_gold_crawler'][0]);
gc_check(strpos($unlicensed_html, 'مجوز استفاده از این ابزار') !== false, 'a signed-in but unlicensed user is told to contact the admin, not asked to log in again');
gc_check(strpos($unlicensed_html, 'gate__icon--warn') !== false, 'the unlicensed gate uses the warn icon variant, not the lock one');
gc_check(strpos($unlicensed_html, 'gate__auth') === false, 'the unlicensed gate has no login/register form - the user is already logged in');

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
gc_check(!isset($GLOBALS['gc_test_actions']['wp_ajax_nopriv_goldcrawler_meta']), 'no nopriv handler exists for the real app actions (logged-out visitors get nothing there)');

// GC_Auth is the sole, deliberate exception: login/register must work for a
// logged-out visitor, so (and only so) those two actions get nopriv hooks.
foreach (array('login', 'register') as $action) {
    gc_check(isset($GLOBALS['gc_test_actions']["wp_ajax_nopriv_goldcrawler_{$action}"]), "wp_ajax_nopriv_goldcrawler_{$action} is registered for logged-out visitors");
    gc_check(isset($GLOBALS['gc_test_actions']["wp_ajax_goldcrawler_{$action}"]), "wp_ajax_goldcrawler_{$action} is also registered for already-logged-in requests");
}

// -- the access-settings admin page is wired up on a real wp-admin load ----
gc_check(isset($GLOBALS['gc_test_actions']['admin_menu']), 'GC_Admin registers on admin_menu when is_admin() is true');
foreach ($GLOBALS['gc_test_actions']['admin_menu'] as $cb) { call_user_func($cb); }
gc_check(count($GLOBALS['gc_test_actions']['menu_pages'] ?? array()) === 1, 'GoldCrawler gets its own top-level entry in the admin sidebar');
$gc_menu = $GLOBALS['gc_test_actions']['menu_pages'][0];
gc_check($gc_menu[3] === GC_Admin::PAGE_SLUG, 'the top-level menu points at the plugin page slug');
gc_check(strpos((string) $gc_menu[5], 'data:image/svg+xml;base64,') === 0, 'the menu icon is an inline data URI (no extra HTTP request)');
gc_check(base64_decode(substr($gc_menu[5], strlen('data:image/svg+xml;base64,')), true) !== false, 'the menu icon payload is valid base64');
// The same slug under two parents makes WordPress resolve the parent file
// ambiguously and highlight the wrong menu, so it must be registered once.
gc_check(empty($GLOBALS['gc_test_actions']['options_pages']), 'the page is no longer also registered under Settings');
gc_check(empty($GLOBALS['gc_test_actions']['submenu_pages']), 'the slug is not registered a second time as a submenu');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
