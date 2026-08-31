<?php
/** Mirrors tests/test_report.py's build_series fixtures. Run: php tests/test-report.php */

define('GC_STANDALONE_TEST', true);
require __DIR__ . '/../includes/class-gc-jalali.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-report.php';

$failures = 0;
$checks = 0;
function gc_check($cond, $label) {
    global $failures, $checks;
    $checks++;
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

function gc_point($close) {
    return array('close' => $close, 'low' => $close - 1000, 'high' => $close + 1000, 'average' => $close);
}

$gold = GC_Symbols::get('geram18');
$points = array(
    '1404/05/28' => gc_point(700000),
    '1404/05/30' => gc_point(730000),
);
$start = array(1404, 5, 28);
$end = array(1404, 6, 1);

$result = GC_Report::build_series($gold, $points, $start, $end);
gc_check(count($result['rows']) === 5, 'fills every day in a 5-day window');
gc_check($result['rows'][0]['live'] === true, 'day one is live (has real data)');
gc_check($result['rows'][1]['close'] === 700000, 'gap day carries the previous close');
gc_check($result['rows'][1]['live'] === false, 'carried day is not live');
gc_check($result['rows'][1]['status'] === GC_Report::STATUS_CARRIED, 'carried status label');

$no_fill = GC_Report::build_series($gold, $points, $start, $end, false);
gc_check(count($no_fill['rows']) === 2, 'fill_gaps=false keeps only trading days');

$stats = $result['stats'];
gc_check($stats['trading_days'] === 2, 'trading_days counts only live rows');
gc_check($stats['first'] === 700000, 'first observed close');
gc_check($stats['last'] === 730000, 'last observed close');
gc_check($stats['min'] === 700000 && $stats['max'] === 730000, 'min/max over observed closes');
gc_check($stats['change'] === 30000, 'change is last - first');
gc_check(abs($stats['change_pct'] - 4.29) < 0.01, 'change_pct rounds to 4.29');
gc_check($stats['unit'] === 'تومان', 'unit label for IRR symbol');

// price before the window is carried in as day one.
$points_with_prior = array('1404/01/01' => gc_point(500000)) + $points;
$before = GC_Report::build_series($gold, $points_with_prior, array(1404, 5, 20), array(1404, 5, 28));
gc_check($before['rows'][0]['close'] === 500000, 'price before window carries into day one');
gc_check($before['rows'][0]['live'] === false, 'carried-in day one is not live');

// no prior data at all -> blank rows.
$blank = GC_Report::build_series($gold, $points, array(1404, 5, 26), $end);
gc_check($blank['rows'][0]['close'] === null, 'no prior data means a blank first row');
gc_check($blank['rows'][0]['status'] === GC_Report::STATUS_MISSING, 'blank row status label');

// empty points -> empty stats, no crash.
$empty = GC_Report::build_series($gold, array(), $start, $end);
gc_check($empty['stats']['trading_days'] === 0, 'empty points give zero trading days');
gc_check($empty['stats']['change'] === null, 'empty points give a null change');

// CSV export: BOM + Persian symbol name present.
$csv = GC_Report::to_csv(array(array('symbol' => $gold, 'rows' => $result['rows'], 'stats' => $stats)));
gc_check(substr($csv, 0, 3) === "\xEF\xBB\xBF", 'csv starts with a UTF-8 BOM');
gc_check(strpos($csv, 'طلای ۱۸ عیار') !== false, 'csv contains the Persian symbol name');

// JSON export round-trips the range and symbol unit.
$json = GC_Report::to_json_payload(
    array(array('symbol' => $gold, 'rows' => $result['rows'], 'stats' => $stats)),
    $start, $end, 'GoldCrawler', '1.0.0'
);
gc_check($json['range']['start'] === '1404/05/28' && $json['range']['end'] === '1404/06/01', 'json range formatted correctly');
gc_check($json['series'][0]['symbol']['unit'] === 'تومان', 'json embeds the symbol unit');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
