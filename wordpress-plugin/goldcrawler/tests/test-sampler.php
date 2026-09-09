<?php
/**
 * Tests for GC_Sampler: it must only run when recording is enabled, must
 * schedule/unschedule the 10-minute cron to follow that setting, and must
 * turn the current TGJU price into an intraday sample.
 */

require __DIR__ . '/wp-stubs.php';
define('GC_STANDALONE_TEST', true);
require __DIR__ . '/../includes/class-gc-jalali.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-tgju.php';
require __DIR__ . '/../includes/class-gc-report.php';
require __DIR__ . '/../includes/class-gc-storage.php';
require __DIR__ . '/../includes/class-gc-crawler.php';
require __DIR__ . '/../includes/class-gc-intraday.php';
require __DIR__ . '/../includes/class-gc-sampler.php';

$failures = 0; $checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) { $failures++; fwrite(STDERR, "FAIL: {$label}\n"); }
}

/** A TGJU response whose newest row carries $price as the close. */
function gc_stub_price($symbol_substring, $price) {
    $today = GC_Jalali::today();
    $jalali = GC_Jalali::format($today[0], $today[1], $today[2]);
    gc_test_stub_remote_get($symbol_substring, array('code' => 200, 'body' => json_encode(array(
        'data' => array(
            // [open, low, high, close, change, percent, gregorian, jalali]
            array((string) $price, (string) $price, (string) $price, (string) $price, '', '', '2025-08-19', $jalali),
        ),
    ))));
}

// -- the 10-minute schedule follows the setting ------------------------------
gc_check(wp_next_scheduled(GC_Sampler::HOOK) === false, 'no sampling cron is scheduled to begin with');

GC_Sampler::sync_schedule(true);
gc_check(wp_next_scheduled(GC_Sampler::HOOK) !== false, 'enabling recording schedules the sampling cron');

GC_Sampler::sync_schedule(true); // idempotent
gc_check(wp_next_scheduled(GC_Sampler::HOOK) !== false, 'syncing again while enabled keeps the single schedule');

GC_Sampler::sync_schedule(false);
gc_check(wp_next_scheduled(GC_Sampler::HOOK) === false, 'disabling recording clears the sampling cron (it must not keep sampling forever)');

$schedules = GC_Sampler::add_schedule(array());
gc_check(
    isset($schedules[GC_Sampler::SCHEDULE]) && $schedules[GC_Sampler::SCHEDULE]['interval'] === 600,
    'a 10-minute cron interval is declared (WordPress ships none)'
);

// -- run() is a no-op unless the site owner turned recording on --------------
GC_Storage::update_settings(array('symbols' => array('geram18'), 'intraday_recording' => false));
gc_stub_price('geram18', 7000000);
gc_check(GC_Sampler::run() === null, 'the cron tick does nothing while recording is off');
gc_check(GC_Intraday::summary() === array(), 'nothing was written to the uploads folder while recording is off');

// -- with recording on, a tick records the live price ------------------------
GC_Storage::update_settings(array('intraday_recording' => true));
$result = GC_Sampler::run();
gc_check(is_array($result) && $result['recorded'] === array('geram18'), 'the cron tick records the watched symbol');
gc_check($result['errors'] === array(), 'a healthy fetch produces no errors');

$summary = GC_Intraday::summary();
gc_check(count($summary) === 1 && $summary[0]['samples'] === 1, 'exactly one sample was stored');

// The stored price must be the close of the newest row, converted to Toman
// (the IRR divisor of 10 that the rest of the plugin applies).
$now = time();
$samples = GC_Intraday::load_samples('geram18', $now - 120, $now + 120);
gc_check(reset($samples) === 700000.0, 'the sampled price is the current close, converted to the display unit');

// -- a fetch failure is reported per symbol, not fatal -----------------------
GC_Storage::update_settings(array('symbols' => array('geram18', 'sekee')));
$GLOBALS['gc_test_remote_get_responses'] = array(); // every fetch now fails
$result = GC_Sampler::sample_now();
gc_check($result['recorded'] === array(), 'nothing is recorded when TGJU cannot be reached');
gc_check(count($result['errors']) === 2, 'each unreachable symbol produces its own error entry');
gc_check(isset($result['errors'][0]['symbol'], $result['errors'][0]['message']), 'errors name the symbol and carry a message');

// -- last_sample is stamped so the UI can show when it last ran --------------
gc_check(GC_Storage::get_settings()['last_sample'] > 0, 'the time of the last sampling pass is recorded');

// -- sample_now() can target explicit symbols -------------------------------
gc_stub_price('price_dollar_rl', 900000);
$result = GC_Sampler::sample_now(array('price_dollar_rl'));
gc_check($result['recorded'] === array('price_dollar_rl'), 'sample_now() honours an explicit symbol list');
$dollar = GC_Intraday::load_samples('price_dollar_rl', $now - 120, time() + 120);
gc_check(reset($dollar) === 90000.0, 'the explicitly sampled symbol stored its own price');


// -- the scheduler records what the ADMIN chose -----------------------------
// A visitor's own report selection and the site's recording list are two
// different decisions; only the second is site-wide.

GC_Storage::update_settings(array(
    'symbols' => array('price_dollar_rl', 'price_eur'),   // a viewer's picks
    'sampler_symbols' => array('geram18', 'sekee'),        // the admin's picks
), true);
gc_check(
    GC_Sampler::scheduled_symbols() === array('geram18', 'sekee'),
    'the scheduler records the admin list, not the viewer symbol list'
);

// With no admin list, fall back to the site's watched symbols so simply
// switching recording on still does something sensible.
GC_Storage::update_settings(array('sampler_symbols' => array()), true);
gc_check(
    GC_Sampler::scheduled_symbols() === array('price_dollar_rl', 'price_eur'),
    'an empty admin list falls back to the watched symbols'
);

// Blank entries must not become requests for a nameless symbol.
GC_Storage::update_settings(array('sampler_symbols' => array('geram18', '', '  ')), true);
gc_check(
    GC_Sampler::scheduled_symbols() === array('geram18'),
    'blank and whitespace-only entries are dropped from the admin list'
);

GC_Storage::update_settings(array('sampler_symbols' => array()), true);

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
