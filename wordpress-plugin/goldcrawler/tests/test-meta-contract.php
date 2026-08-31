<?php
/**
 * Regression test for the "محل ذخیره داده‌ها: undefined" bug: app.js was
 * copied from the desktop app and still read `meta.dataDir`, a field the
 * WordPress AJAX handler never sends (there is no single "data directory"
 * concept server-side the same way). This cross-checks every `meta.<field>`
 * access in app.js against the actual keys handle_meta() returns, so a
 * future copy-paste from the desktop app.js can't reintroduce the same
 * class of bug silently.
 */

require __DIR__ . '/wp-stubs.php';
define('GC_STANDALONE_TEST', true);
define('GOLDCRAWLER_VERSION', '1.0.1');
require __DIR__ . '/../includes/class-gc-jalali.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-tgju.php';
require __DIR__ . '/../includes/class-gc-report.php';
require __DIR__ . '/../includes/class-gc-storage.php';
require __DIR__ . '/../includes/class-gc-crawler.php';
require __DIR__ . '/../includes/class-gc-xlsx.php';
require __DIR__ . '/../includes/class-gc-license.php';
require __DIR__ . '/../includes/class-gc-ajax.php';

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

// -- get the real set of keys handle_meta() actually returns ----------------
$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce(GC_Ajax::NONCE_ACTION);
$GLOBALS['gc_test_current_user_can'] = true;
$GLOBALS['gc_test_last_json'] = null;
ob_start();
try {
    GC_Ajax::handle_meta();
} catch (Throwable $e) {
    // expected: wp_send_json_success() "died" here, same as real WordPress
}
ob_get_clean();

$meta_keys = array_keys($GLOBALS['gc_test_last_json']['data']['data']);
gc_check(!empty($meta_keys), 'handle_meta() actually returned some data to check against');

// -- scan app.js for every `meta.<identifier>` property access --------------
// Scoped to init(), the one place the real API-response object is named
// `meta` - elsewhere in the file "meta" is reused as an unrelated local
// variable name (e.g. a DOM node for a stat card), which would otherwise
// produce false positives here.
$js = file_get_contents(__DIR__ . '/../assets/app.js');
if (!preg_match('/async function init\(\)\s*\{(.*?)\n\}/s', $js, $fn)) {
    gc_check(false, 'could not locate init() in app.js to scan (test itself needs updating)');
    $fn = array('', '');
}
preg_match_all('/\bmeta\.([A-Za-z_][A-Za-z0-9_]*)/', $fn[1], $matches);
$referenced = array_unique($matches[1]);
gc_check(!empty($referenced), 'app.js references at least one meta.* field (sanity check on the regex itself)');

$unknown = array_diff($referenced, $meta_keys);
gc_check($unknown === array(), 'every meta.<field> app.js reads is actually present in handle_meta()\'s response' .
    ($unknown ? ' (missing: ' . implode(', ', $unknown) . ')' : ''));

// -- the specific bug must not come back -------------------------------------
gc_check(strpos($js, 'meta.dataDir') === false, 'app.js no longer references the desktop-only meta.dataDir field');
gc_check(strpos($js, "\$('dataDir')") === false, "app.js no longer looks up a #dataDir element that the WP template does not render");

$template = file_get_contents(__DIR__ . '/../includes/template-app.php');
gc_check(strpos($template, 'id="dataDir"') === false, 'the WordPress template no longer renders a #dataDir element');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
