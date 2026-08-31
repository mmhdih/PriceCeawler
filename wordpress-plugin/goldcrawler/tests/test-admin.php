<?php
/** Functional tests for GC_Admin (Settings → GoldCrawler access page). */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-gc-license.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-storage.php';
require __DIR__ . '/../includes/class-gc-crawler.php';
require __DIR__ . '/../includes/class-gc-admin.php';

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

gc_test_add_user(1, 'مدیر سایت', 'admin', 'admin@example.test', array('administrator'));
gc_test_add_user(2, 'کاربر اول', 'user1', 'user1@example.test', array('subscriber'));
gc_test_add_user(3, 'کاربر دوم', 'user2', 'user2@example.test', array('editor'));

$_SERVER['REQUEST_METHOD'] = 'GET';

// -- rendering requires manage_options --------------------------------------
$GLOBALS['gc_test_current_user_can'] = false;
try {
    ob_start();
    GC_Admin::render_page();
    ob_end_clean();
    gc_check(false, 'render_page should have called wp_die() for a non-admin');
} catch (RuntimeException $e) {
    gc_check(strpos($e->getMessage(), 'wp_die') === 0, 'non-admins are turned away with wp_die()');
}

$GLOBALS['gc_test_current_user_can'] = true;
ob_start();
GC_Admin::render_page();
$html = ob_get_clean();
gc_check(strpos($html, 'کاربر اول') !== false && strpos($html, 'کاربر دوم') !== false, 'the page lists registered users');
gc_check(strpos($html, 'disabled') !== false, 'the administrator row is shown as always-on (disabled checkbox)');

// -- saving grants access to the checked users -------------------------------
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
    'goldcrawler_save' => '1',
    'goldcrawler_nonce' => wp_create_nonce(GC_Admin::NONCE_ACTION),
    'goldcrawler_users' => array('2'),
);
ob_start();
GC_Admin::render_page();
$html = ob_get_clean();
gc_check(GC_License::is_licensed(2), 'checking a user in the form grants them a license');
gc_check(!GC_License::is_licensed(3), 'an unchecked user is not granted a license');
gc_check(strpos($html, 'notice-success') !== false, 'a success notice is shown after saving');

// -- a bad nonce is rejected, existing grants stay untouched -----------------
$_POST = array('goldcrawler_save' => '1', 'goldcrawler_nonce' => 'wrong', 'goldcrawler_users' => array());
ob_start();
GC_Admin::render_page();
$html = ob_get_clean();
gc_check(GC_License::is_licensed(2), 'a forged/expired nonce does not change existing grants');
gc_check(strpos($html, 'notice-error') !== false, 'a bad nonce shows an error notice');

// -- allow-all toggle ---------------------------------------------------
$GLOBALS['gc_test_current_user_can'] = true;
ob_start();
GC_Admin::render_page();
$html = ob_get_clean();
gc_check(strpos($html, 'goldcrawler_allow_all') !== false, 'the page renders the allow-all-users checkbox');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
    'goldcrawler_save' => '1',
    'goldcrawler_nonce' => wp_create_nonce(GC_Admin::NONCE_ACTION),
    'goldcrawler_allow_all' => '1',
    // disabled checkboxes never submit, so the real form sends no
    // goldcrawler_users at all while allow-all is checked
);
ob_start();
GC_Admin::render_page();
ob_end_clean();
gc_check(GC_License::allow_all_enabled() === true, 'checking allow-all turns it on');
gc_check(GC_License::is_licensed(2), 'turning on allow-all does not wipe the existing per-user allowlist');

$_POST = array(
    'goldcrawler_save' => '1',
    'goldcrawler_nonce' => wp_create_nonce(GC_Admin::NONCE_ACTION),
    'goldcrawler_users' => array('3'),
    // allow_all checkbox omitted == unchecked
);
ob_start();
GC_Admin::render_page();
ob_end_clean();
gc_check(GC_License::allow_all_enabled() === false, 'unchecking allow-all turns it back off');
gc_check(GC_License::is_licensed(3) && !GC_License::is_licensed(2), 'once allow-all is off, the submitted per-user list is saved normally again');

// -- symbol management: rendering --------------------------------------
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
GC_Admin::render_page();
$html = ob_get_clean();
gc_check(strpos($html, 'geram18') !== false && strpos($html, 'price_dollar_rl') !== false, 'the symbols section lists built-in catalog keys');
gc_check(strpos($html, 'goldcrawler_new_key') !== false, 'the symbols section renders the add-new-symbol form fields');

// -- symbol management: disabling a built-in symbol --------------------------
$catalog_keys = array_keys(GC_Symbols::catalog());
$still_enabled = array_values(array_diff($catalog_keys, array('geram18')));

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
    'goldcrawler_save_symbols' => '1',
    'goldcrawler_symbols_nonce' => wp_create_nonce(GC_Admin::NONCE_ACTION_SYMBOLS),
    'goldcrawler_enabled' => $still_enabled, // geram18 omitted == unchecked == disabled
);
ob_start();
GC_Admin::render_page();
$html = ob_get_clean();
gc_check(in_array('geram18', GC_Storage::get_settings()['disabled_symbols'], true), 'unchecking a built-in symbol disables it');
gc_check(!array_key_exists('geram18', GC_Crawler::known_symbols()), 'a disabled built-in symbol is dropped from known_symbols()');
gc_check(array_key_exists('sekee', GC_Crawler::known_symbols()), 'other built-in symbols stay enabled');
gc_check(strpos($html, 'notice-success') !== false, 'a success notice is shown after saving symbols');

// -- symbol management: adding a custom symbol -------------------------------
$_POST = array(
    'goldcrawler_save_symbols' => '1',
    'goldcrawler_symbols_nonce' => wp_create_nonce(GC_Admin::NONCE_ACTION_SYMBOLS),
    'goldcrawler_enabled' => $catalog_keys, // re-enable geram18 too, for the next checks
    'goldcrawler_new_key' => 'price_xau_usd',
    'goldcrawler_new_name' => 'طلای جهانی (تست)',
    'goldcrawler_new_group' => 'گروه تستی',
    'goldcrawler_new_currency' => 'USD',
    'goldcrawler_new_decimals' => '2',
);
ob_start();
GC_Admin::render_page();
ob_end_clean();
$known = GC_Crawler::known_symbols();
gc_check(isset($known['price_xau_usd']), 'a newly added custom symbol appears in known_symbols()');
gc_check($known['price_xau_usd']['name'] === 'طلای جهانی (تست)', 'the custom symbol keeps its given display name');
gc_check($known['price_xau_usd']['group'] === 'گروه تستی', 'the custom symbol keeps its given group');
gc_check($known['price_xau_usd']['currency'] === 'USD', 'the custom symbol keeps its given currency');
gc_check($known['price_xau_usd']['decimals'] === 2, 'the custom symbol keeps its given decimals');
gc_check(array_key_exists('geram18', $known), 're-enabling geram18 in the same save restores it');

// -- symbol management: an invalid new key is rejected, nothing else saved --
$_POST = array(
    'goldcrawler_save_symbols' => '1',
    'goldcrawler_symbols_nonce' => wp_create_nonce(GC_Admin::NONCE_ACTION_SYMBOLS),
    'goldcrawler_enabled' => array(), // would otherwise disable everything
    'goldcrawler_new_key' => 'not a valid key!',
);
ob_start();
GC_Admin::render_page();
$html = ob_get_clean();
gc_check(strpos($html, 'notice-error') !== false, 'an invalid new symbol key shows an error notice');
gc_check(array_key_exists('sekee', GC_Crawler::known_symbols()), 'rejecting the invalid key leaves the enabled-symbols list untouched');

// -- symbol management: deleting a custom symbol -----------------------------
$_POST = array(
    'goldcrawler_save_symbols' => '1',
    'goldcrawler_symbols_nonce' => wp_create_nonce(GC_Admin::NONCE_ACTION_SYMBOLS),
    'goldcrawler_enabled' => $catalog_keys,
    'goldcrawler_delete_custom' => array('price_xau_usd'),
);
ob_start();
GC_Admin::render_page();
ob_end_clean();
gc_check(!array_key_exists('price_xau_usd', GC_Crawler::known_symbols()), 'checking a custom symbol for deletion removes it');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = array();

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
