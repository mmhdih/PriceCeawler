<?php
/**
 * Regression test for the sidebar/search overflow bug: `.layout` lays the
 * sidebar out as a CSS grid column with a *fixed* width (`--sidebar`), but
 * grid items default to `min-width: auto`, which resolves to their content's
 * min-content size. The search `<input>`'s intrinsic minimum width can
 * exceed that fixed track, so without an explicit `min-width: 0` on
 * `.sidebar`, the sidebar (and the search box inside it) blows out past its
 * own border - independent of any host theme, which is why it reproduced
 * identically on every system tested.
 */

$css = file_get_contents(__DIR__ . '/../assets/styles.css');

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

if (!preg_match('/\.goldcrawler-app \.sidebar\s*\{([^}]*)\}/s', $css, $m)) {
    gc_check(false, 'could not locate the .goldcrawler-app .sidebar rule (test itself needs updating)');
    $m = array('', '');
}
gc_check(
    (bool) preg_match('/min-width\s*:\s*0\b/', $m[1]),
    '.goldcrawler-app .sidebar sets min-width: 0 so it can shrink below its grid-item content minimum'
);

/**
 * Regression test for a second, compounding overflow cause found on the
 * live site: its active theme carries its own input[type=search]{box-sizing:
 * content-box} rule at the same specificity as our universal
 * ".goldcrawler-app *" border-box reset, and wins cascade ties by load
 * order. With content-box, width:100% + padding renders wider than the
 * container - confirmed live via getComputedStyle() showing box-sizing:
 * content-box on #symbolSearch despite this file setting border-box.
 * Both rules need !important to reliably win regardless of theme CSS order.
 */
if (!preg_match('/\.goldcrawler-app,\s*\.goldcrawler-app \*\s*\{([^}]*)\}/s', $css, $m2)) {
    gc_check(false, 'could not locate the universal ".goldcrawler-app, .goldcrawler-app *" box-sizing rule (test itself needs updating)');
    $m2 = array('', '');
}
gc_check(
    (bool) preg_match('/box-sizing\s*:\s*border-box\s*!important/', $m2[1]),
    'the universal box-sizing reset uses !important so a same-specificity theme rule cannot win it back to content-box'
);

if (!preg_match('/\.goldcrawler-app input\[type="text"\][^{]*\{([^}]*)\}/s', $css, $m3)) {
    gc_check(false, 'could not locate the .goldcrawler-app input[type="text"]/... rule (test itself needs updating)');
    $m3 = array('', '');
}
gc_check(
    (bool) preg_match('/box-sizing\s*:\s*border-box\s*!important/', $m3[1]),
    'the text/search input rule also pins box-sizing: border-box !important directly, not just via the universal reset'
);

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
