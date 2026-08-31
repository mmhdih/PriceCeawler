<?php
/** Functional tests for GC_Admin (Settings → GoldCrawler access page). */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-gc-license.php';
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

$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = array();

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
