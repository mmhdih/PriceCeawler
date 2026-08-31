<?php
/** End-to-end tests of GC_Ajax handlers through the WP stub shim. */

require __DIR__ . '/wp-stubs.php';
define('GC_STANDALONE_TEST', true);
define('GOLDCRAWLER_VERSION', '1.0.0');
require __DIR__ . '/../includes/class-gc-jalali.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-tgju.php';
require __DIR__ . '/../includes/class-gc-report.php';
require __DIR__ . '/../includes/class-gc-storage.php';
require __DIR__ . '/../includes/class-gc-crawler.php';
require __DIR__ . '/../includes/class-gc-xlsx.php';
require __DIR__ . '/../includes/class-gc-ajax.php';

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
    $_SERVER['HTTP_X_WP_NONCE'] = $with_nonce ? wp_create_nonce(GC_Ajax::NONCE_ACTION) : 'wrong';

    ob_start();
    try {
        call_user_func($handler);
    } catch (Throwable $e) {
        // Expected: wp_send_json_*() or handle_export()'s terminate() "died"
        // here, same as real WordPress's wp_die()/exit would in production.
    }
    $output = ob_get_clean();
    return array($GLOBALS['gc_test_last_json'], $output);
}

// -- meta: requires capability + nonce -------------------------------------
$GLOBALS['gc_test_current_user_can'] = false;
gc_stage_body(array());
list($resp,) = gc_call(array('GC_Ajax', 'handle_meta'));
gc_check($resp['status'] === 403 && $resp['data']['success'] === false, 'meta is refused without the read capability');

$GLOBALS['gc_test_current_user_can'] = true;
list($resp,) = gc_call(array('GC_Ajax', 'handle_meta'), false);
gc_check($resp['status'] === 403, 'meta is refused with a bad nonce even if logged in as admin');

list($resp,) = gc_call(array('GC_Ajax', 'handle_meta'));
gc_check($resp['data']['success'] === true, 'meta succeeds for an admin with a valid nonce');
$meta = $resp['data']['data'];
gc_check(count($meta['symbols']) === 32, 'meta lists the full built-in catalog');
gc_check(count($meta['presets']) === 7, 'meta includes all 7 range presets');

// -- meta: CAPABILITY = 'read' means every logged-in role, not just admins --
$GLOBALS['gc_test_user_role'] = 'administrator';
list($resp,) = gc_call(array('GC_Ajax', 'handle_meta'));
gc_check($resp['data']['success'] === true, 'an Administrator can use the tool');

$GLOBALS['gc_test_user_role'] = 'subscriber';
list($resp,) = gc_call(array('GC_Ajax', 'handle_meta'));
gc_check($resp['data']['success'] === true, 'a plain Subscriber (any logged-in user) can use the tool, not just admins');

$GLOBALS['gc_test_user_role'] = 'logged_out';
list($resp,) = gc_call(array('GC_Ajax', 'handle_meta'));
gc_check($resp['status'] === 403 && $resp['data']['success'] === false, 'a logged-out visitor is still refused');
$GLOBALS['gc_test_user_role'] = null; // restore the legacy blanket-boolean mode for the rest of this file

// -- series: validation errors ------------------------------------------
gc_stage_body(array('symbols' => array(), 'start' => '1404/01/01', 'end' => '1404/01/10'));
list($resp,) = gc_call(array('GC_Ajax', 'handle_series'));
gc_check($resp['status'] === 400 && strpos($resp['data']['data']['message'], 'نماد') !== false, 'series rejects an empty symbol list');

gc_stage_body(array('symbols' => array('geram18'), 'start' => '1404/05/10', 'end' => '1404/05/01'));
list($resp,) = gc_call(array('GC_Ajax', 'handle_series'));
gc_check($resp['status'] === 400 && strpos($resp['data']['data']['message'], 'تاریخ شروع') !== false, 'series rejects a backwards date range');

// -- series: success with a stubbed TGJU response -------------------------
gc_test_stub_remote_get('geram18', array('code' => 200, 'body' => json_encode(array('data' => array(
    array('7,000,000', '6,990,000', '7,010,000', '7,000,000', '', '', '2025-08-19', '1404/05/28'),
)))));
gc_stage_body(array('symbols' => array('geram18'), 'start' => '1404/05/28', 'end' => '1404/05/28'));
list($resp,) = gc_call(array('GC_Ajax', 'handle_series'));
gc_check($resp['data']['success'] === true, 'series succeeds with valid input');
gc_check($resp['data']['data']['series'][0]['symbol']['key'] === 'geram18', 'series returns the requested symbol');

// -- export: xlsx bytes come back as a real zip ---------------------------
gc_stage_body(array('symbols' => array('geram18'), 'start' => '1404/05/28', 'end' => '1404/05/28', 'format' => 'xlsx'));
list(, $raw) = gc_call(array('GC_Ajax', 'handle_export'));
gc_check(substr($raw, 0, 2) === 'PK', 'export xlsx output starts with a zip signature');

// -- export: unsupported format is rejected -------------------------------
gc_stage_body(array('symbols' => array('geram18'), 'start' => '1404/05/28', 'end' => '1404/05/28', 'format' => 'pdf'));
list($resp,) = gc_call(array('GC_Ajax', 'handle_export'));
gc_check($resp['status'] === 400, 'export rejects an unsupported format');

// -- settings + symbols + crawl round trip --------------------------------
gc_stage_body(array('theme' => 'dark'));
list($resp,) = gc_call(array('GC_Ajax', 'handle_settings'));
gc_check($resp['data']['data']['settings']['theme'] === 'dark', 'settings update round-trips');

gc_stage_body(array('key' => 'bad key!', 'name' => 'x'));
list($resp,) = gc_call(array('GC_Ajax', 'handle_symbols'));
gc_check($resp['status'] === 400, 'invalid custom symbol key is rejected');

gc_stage_body(array('key' => 'my_symbol', 'name' => 'نماد من'));
list($resp,) = gc_call(array('GC_Ajax', 'handle_symbols'));
$keys = array_column($resp['data']['data']['symbols'], 'key');
gc_check(in_array('my_symbol', $keys, true), 'a valid custom symbol is added and returned');

gc_stage_body(array('symbols' => array('geram18')));
list($resp,) = gc_call(array('GC_Ajax', 'handle_crawl'));
gc_check($resp['data']['success'] === true, 'crawl handler runs successfully');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
