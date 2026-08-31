<?php
/**
 * Mirrors tests/test_tgju.py's fixtures so both languages agree on the same
 * (deliberately messy) TGJU-shaped input. Run: php tests/test-tgju.php
 */

define('GC_STANDALONE_TEST', true);
require __DIR__ . '/../includes/class-gc-jalali.php';
require __DIR__ . '/../includes/class-gc-symbols.php';
require __DIR__ . '/../includes/class-gc-tgju.php';

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

$gold = GC_Symbols::get('geram18');
$ons = GC_Symbols::get('ons');

// 1) standard row, Rial -> Toman conversion.
$rows = GC_Tgju::parse_rows(array('data' => array(
    array('7,500,000', '7,480,000', '7,560,000', '7,540,000', '40,000', '0.53', '2025-08-20', '1404/05/29'),
)), $gold);
$row = $rows['1404/05/29'];
gc_check(count($rows) === 1, 'one row parsed');
gc_check($row['gregorian'] === '2025-08-20', 'gregorian carried through');
gc_check($row['low'] === 748000.0, 'low divided by 10 (Rial->Toman)');
gc_check($row['high'] === 756000.0, 'high divided by 10');
gc_check($row['close'] === 754000.0, 'close divided by 10');
gc_check(abs($row['average'] - (748000.0 + 756000.0 + 754000.0) / 3) < 1e-9, 'average of low/high/close');

// 2) USD symbols are not divided.
$rows = GC_Tgju::parse_rows(array('data' => array(
    array('3,350.5', '3,340.1', '3,360.9', '3,355.2', '5', '0.1', '2025-08-20', '1404/05/29'),
)), $ons);
gc_check(abs($rows['1404/05/29']['close'] - 3355.2) < 1e-9, 'USD symbol not divided');

// 3) HTML tags and Persian digits.
$rows = GC_Tgju::parse_rows(array('data' => array(
    array('۷,۵۰۰,۰۰۰', '7,480,000', '7,560,000', "<span class='high'>7,540,000</span>", '-', '-', '2025-08-20', '۱۴۰۴/۰۵/۲۹'),
)), $gold);
gc_check($rows['1404/05/29']['close'] === 754000.0, 'HTML tags and Persian digits handled');

// 4) rows are sorted + de-duplicated, later duplicate wins.
$rows = GC_Tgju::parse_rows(array('data' => array(
    array('1', '100', '110', '105', '', '', '2025-08-21', '1404/05/30'),
    array('1', '100', '110', '105', '', '', '2025-08-20', '1404/05/29'),
    array('1', '200', '220', '210', '', '', '2025-08-21', '1404/05/30'),
)), $gold);
gc_check(array_keys($rows) === array('1404/05/29', '1404/05/30'), 'sorted ascending by date');
gc_check($rows['1404/05/30']['close'] === 21.0, 'later duplicate wins');

// 5) swapped low/high are corrected.
$rows = GC_Tgju::parse_rows(array('data' => array(
    array('100', '9,999,999', '1,000,000', '1,200,000', '', '', '2025-08-20', '1404/05/29'),
)), $gold);
gc_check($rows['1404/05/29']['low'] <= $rows['1404/05/29']['high'], 'swapped low/high corrected');

// 6) invalid rows raise a Persian error.
try {
    GC_Tgju::parse_rows(array('data' => array()), $gold);
    gc_check(false, 'empty payload should throw');
} catch (GC_Tgju_Exception $e) {
    gc_check(strpos($e->getMessage(), 'geram18') !== false, 'empty payload error mentions symbol key');
}

// 7) dict-shaped rows (associative arrays from json_decode).
$rows = GC_Tgju::parse_rows(array('data' => array(
    array(
        'open' => '1', 'low' => '100', 'high' => '110', 'close' => '105',
        'change' => '', 'percent' => '', 'date_gregorian' => '2025-08-20', 'date' => '1404/05/29',
    ),
)), $gold);
gc_check($rows['1404/05/29']['close'] === 10.5, 'associative-array rows handled');

// 8) missing close recovered from low/high midpoint.
$rows = GC_Tgju::parse_rows(array('data' => array(
    array('-', '1,000,000', '1,200,000', '-', '', '', '2025-08-20', '1404/05/29'),
)), $gold);
gc_check($rows['1404/05/29']['close'] === 110000.0, 'missing close recovered from low/high');

// 9) custom symbol defaults to Rial (divide by 10).
$custom = GC_Symbols::custom('some_key', 'نماد آزمایشی');
$rows = GC_Tgju::parse_rows(array('data' => array(
    array('1', '10', '20', '30', '', '', '2025-08-20', '1404/05/29'),
)), $custom);
gc_check($rows['1404/05/29']['close'] === 3.0, 'custom symbol defaults to Rial divisor');

echo "checks: {$checks}, failures: {$failures}\n";
exit($failures > 0 ? 1 : 0);
