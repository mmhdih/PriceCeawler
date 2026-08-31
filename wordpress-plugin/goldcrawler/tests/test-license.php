<?php
/** Functional tests for GC_License (the per-user access allowlist). */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-gc-license.php';

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

// -- storage --------------------------------------------------------------
gc_check(GC_License::licensed_user_ids() === array(), 'no one is licensed by default');

GC_License::grant(5);
GC_License::grant(9);
gc_check(GC_License::is_licensed(5) && GC_License::is_licensed(9), 'granted users are licensed');
gc_check(!GC_License::is_licensed(7), 'an ungranted user is not licensed');

GC_License::grant(5); // duplicate grant must not create a duplicate entry
gc_check(count(GC_License::licensed_user_ids()) === 2, 'granting twice does not duplicate');

GC_License::revoke(5);
gc_check(!GC_License::is_licensed(5) && GC_License::is_licensed(9), 'revoke removes only the targeted user');

GC_License::set_licensed_user_ids(array('3', '4', 'not-a-number', '3'));
gc_check(GC_License::licensed_user_ids() === array(3, 4), 'set_licensed_user_ids sanitises to unique ints');

// -- current_user_allowed() --------------------------------------------------
GC_License::set_licensed_user_ids(array(9));
$GLOBALS['gc_test_current_user_id'] = 9;

$GLOBALS['gc_test_user_role'] = 'logged_out';
gc_check(GC_License::current_user_allowed() === false, 'a logged-out visitor is never allowed, even if their ID happens to be licensed');

$GLOBALS['gc_test_user_role'] = 'subscriber';
gc_check(GC_License::current_user_allowed() === true, 'a logged-in licensed Subscriber is allowed');

$GLOBALS['gc_test_current_user_id'] = 123; // not in the licensed list
gc_check(GC_License::current_user_allowed() === false, 'a logged-in but unlicensed Subscriber is refused');

$GLOBALS['gc_test_user_role'] = 'administrator';
gc_check(GC_License::current_user_allowed() === true, 'an Administrator is always allowed, licensed or not');

// -- allow_all() site-wide override ------------------------------------------
GC_License::set_licensed_user_ids(array()); // nobody explicitly licensed
gc_check(GC_License::allow_all_enabled() === false, 'allow-all is off by default');

$GLOBALS['gc_test_user_role'] = 'subscriber';
$GLOBALS['gc_test_current_user_id'] = 999; // not licensed
gc_check(GC_License::current_user_allowed() === false, 'an unlicensed Subscriber is refused while allow-all is off');

GC_License::set_allow_all(true);
gc_check(GC_License::allow_all_enabled() === true, 'set_allow_all(true) turns it on');
gc_check(GC_License::current_user_allowed() === true, 'once allow-all is on, an unlicensed logged-in Subscriber is allowed');

$GLOBALS['gc_test_user_role'] = 'logged_out';
gc_check(GC_License::current_user_allowed() === false, 'allow-all never grants a logged-out visitor');

GC_License::set_allow_all(false);
$GLOBALS['gc_test_user_role'] = 'subscriber';
gc_check(GC_License::current_user_allowed() === false, 'turning allow-all back off refuses the same unlicensed Subscriber again');

$GLOBALS['gc_test_user_role'] = null; // restore legacy default for any other test files sharing this process

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
