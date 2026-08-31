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

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
