<?php
/**
 * End-to-end tests of GC_Auth (the inline login/registration handlers behind
 * the two deliberate wp_ajax_nopriv_* actions in this plugin).
 */

require __DIR__ . '/wp-stubs.php';
define('GC_STANDALONE_TEST', true);
require __DIR__ . '/../includes/class-gc-ajax.php';
require __DIR__ . '/../includes/class-gc-auth.php';

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

function gc_stage_body($array) {
    GC_Ajax::$test_body_override = json_encode($array);
}

/** Calls a handler, capturing its wp_send_json_* output instead of letting it "die". */
function gc_call($handler, $with_nonce = true) {
    $GLOBALS['gc_test_last_json'] = null;
    $_SERVER['HTTP_X_WP_NONCE'] = $with_nonce ? wp_create_nonce(GC_Auth::NONCE_ACTION) : 'wrong';
    ob_start();
    try {
        call_user_func($handler);
    } catch (Throwable $e) {
        // expected: wp_send_json_*() "died" here, same as real WordPress
    }
    ob_get_clean();
    return $GLOBALS['gc_test_last_json'];
}

$GLOBALS['gc_test_user_role'] = 'logged_out';
$GLOBALS['gc_test_options']['users_can_register'] = true;

// -- a bad/missing nonce is rejected for both actions ------------------------
gc_stage_body(array('username' => 'someone', 'password' => 'irrelevant'));
$result = gc_call(array('GC_Auth', 'handle_login'), false);
gc_check($result['status'] === 403, 'login with a bad nonce is rejected (403)');
gc_check($result['data']['success'] === false, 'login with a bad nonce reports failure');

gc_stage_body(array('username' => 'someone', 'email' => 'a@b.test', 'password' => 'abcdef'));
$result = gc_call(array('GC_Auth', 'handle_register'), false);
gc_check($result['status'] === 403, 'register with a bad nonce is rejected (403)');

// -- registration: validation --------------------------------------------
gc_stage_body(array('username' => '', 'email' => '', 'password' => ''));
$result = gc_call(array('GC_Auth', 'handle_register'));
gc_check($result['data']['success'] === false, 'registering with all-blank fields is rejected');

gc_stage_body(array('username' => 'newuser1', 'email' => 'not-an-email', 'password' => 'abcdef'));
$result = gc_call(array('GC_Auth', 'handle_register'));
gc_check($result['data']['success'] === false, 'registering with an invalid email is rejected');

gc_stage_body(array('username' => 'newuser1', 'email' => 'newuser1@example.test', 'password' => '123'));
$result = gc_call(array('GC_Auth', 'handle_register'));
gc_check($result['data']['success'] === false, 'a password shorter than 6 characters is rejected');

// -- registration: happy path, and it signs the new account straight in -----
gc_check(is_user_logged_in() === false, 'sanity check: still logged out before registering');
gc_stage_body(array('username' => 'newuser1', 'email' => 'newuser1@example.test', 'password' => 'a-real-password'));
$result = gc_call(array('GC_Auth', 'handle_register'));
gc_check($result['data']['success'] === true, 'registering with valid, unique details succeeds');
gc_check(is_user_logged_in() === true, 'a successful registration signs the new account in immediately (no separate login step)');

// -- registration: duplicate username/email are rejected ---------------------
$GLOBALS['gc_test_user_role'] = 'logged_out'; // simulate a fresh, still-anonymous visitor for the next checks
gc_stage_body(array('username' => 'newuser1', 'email' => 'someone-else@example.test', 'password' => 'another-password'));
$result = gc_call(array('GC_Auth', 'handle_register'));
gc_check($result['data']['success'] === false, 'registering with a username that already exists is rejected');

gc_stage_body(array('username' => 'brand-new-name', 'email' => 'newuser1@example.test', 'password' => 'another-password'));
$result = gc_call(array('GC_Auth', 'handle_register'));
gc_check($result['data']['success'] === false, 'registering with an email that already exists is rejected');

// -- registration disabled site-wide (and WooCommerce not active) -----------
$GLOBALS['gc_test_options']['users_can_register'] = false;
gc_stage_body(array('username' => 'someoneelse', 'email' => 'someoneelse@example.test', 'password' => 'a-real-password'));
$result = gc_call(array('GC_Auth', 'handle_register'));
gc_check($result['data']['success'] === false, 'registration is refused outright when the site does not allow self-registration');
$GLOBALS['gc_test_options']['users_can_register'] = true;

// -- login: happy path and failure paths -------------------------------------
$GLOBALS['gc_test_user_role'] = 'logged_out';
gc_stage_body(array('username' => 'newuser1', 'password' => 'wrong-password'));
$result = gc_call(array('GC_Auth', 'handle_login'));
gc_check($result['data']['success'] === false, 'logging in with the wrong password is rejected');
gc_check(is_user_logged_in() === false, 'a failed login attempt does not sign anyone in');

gc_stage_body(array('username' => 'newuser1', 'password' => 'a-real-password'));
$result = gc_call(array('GC_Auth', 'handle_login'));
gc_check($result['data']['success'] === true, 'logging in with the correct username and password succeeds');
gc_check(is_user_logged_in() === true, 'a successful login signs the user in');

$GLOBALS['gc_test_user_role'] = 'logged_out';
gc_stage_body(array('username' => 'newuser1@example.test', 'password' => 'a-real-password'));
$result = gc_call(array('GC_Auth', 'handle_login'));
gc_check($result['data']['success'] === true, 'logging in with the registered email address (not just the username) also works');

$GLOBALS['gc_test_user_role'] = 'logged_out';
gc_stage_body(array('username' => '', 'password' => ''));
$result = gc_call(array('GC_Auth', 'handle_login'));
gc_check($result['data']['success'] === false, 'logging in with blank fields is rejected');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
