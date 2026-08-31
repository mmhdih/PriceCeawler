<?php
/**
 * Build daily series from raw TGJU rows, with statistics.
 *
 * Ported from priceceawler/report.py. No WordPress dependency.
 */

if (!defined('ABSPATH') && !defined('GC_STANDALONE_TEST')) {
    exit;
}

final class GC_Report {

    const STATUS_LIVE = 'معامله شده';
    const STATUS_CARRIED = 'بدون معامله (قیمت روز قبل)';
    const STATUS_MISSING = 'بدون داده';

    private static function round_value($value, $decimals) {
        if ($value === null) {
            return null;
        }
        return $decimals <= 0 ? (int) round($value) : round($value, $decimals);
    }

    /**
     * @param array $symbol from GC_Symbols
     * @param array $points  date => row, as returned by GC_Tgju::parse_rows()
     * @param array $start   [y, m, d]
     * @param array $end     [y, m, d]
     * @return array{rows: array[], stats: array}
     */
    public static function build_series($symbol, $points, $start, $end, $fill_gaps = true) {
        $decimals = $symbol['decimals'];
        $start_key = GC_Jalali::format($start[0], $start[1], $start[2]);

        $carried = null;
        foreach ($points as $date => $point) {
            if ($date < $start_key) {
                $carried = $point;
            } else {
                break; // $points is ksort()-ed ascending
            }
        }

        $dates = GC_Jalali::date_range($start[0], $start[1], $start[2], $end[0], $end[1], $end[2]);
        $rows = array();
        $observed = array();
        $first_close = null;
        $last_close = null;

        foreach ($dates as $key) {
            $point = isset($points[$key]) ? $points[$key] : null;
            if ($point !== null) {
                $carried = $point;
                $status = self::STATUS_LIVE;
            } elseif ($fill_gaps && $carried !== null) {
                $point = $carried;
                $status = self::STATUS_CARRIED;
            } elseif ($fill_gaps) {
                $point = null;
                $status = self::STATUS_MISSING;
            } else {
                continue;
            }

            list($y, $m, $d) = array_map('intval', explode('/', $key));
            $weekday = GC_Jalali::weekday_name($y, $m, $d);

            if ($point === null) {
                $rows[] = array(
                    'date' => $key, 'weekday' => $weekday,
                    'low' => null, 'high' => null, 'close' => null, 'average' => null,
                    'status' => $status, 'live' => false,
                );
                continue;
            }

            $close = self::round_value($point['close'], $decimals);
            if ($status === self::STATUS_LIVE && $close !== null) {
                $observed[] = $close;
                if ($first_close === null) {
                    $first_close = $close;
                }
                $last_close = $close;
            }

            $rows[] = array(
                'date' => $key, 'weekday' => $weekday,
                'low' => self::round_value($point['low'], $decimals),
                'high' => self::round_value($point['high'], $decimals),
                'close' => $close,
                'average' => self::round_value($point['average'], $decimals),
                'status' => $status, 'live' => $status === self::STATUS_LIVE,
            );
        }

        $change = null;
        $change_pct = null;
        if ($first_close !== null && $last_close !== null && $first_close != 0) {
            $change = self::round_value($last_close - $first_close, $decimals);
            $change_pct = round((($last_close - $first_close) / $first_close) * 100, 2);
        }

        $stats = array(
            'days' => count($rows),
            'trading_days' => count($observed),
            'first' => $first_close,
            'last' => $last_close,
            'min' => $observed ? min($observed) : null,
            'max' => $observed ? max($observed) : null,
            'mean' => $observed ? self::round_value(array_sum($observed) / count($observed), $decimals) : null,
            'change' => $change,
            'change_pct' => $change_pct,
            'unit' => GC_Symbols::unit_label($symbol['currency']),
        );

        return array('rows' => $rows, 'stats' => $stats);
    }

    const COLUMNS = array('تاریخ شمسی', 'روز هفته', 'کمترین', 'بیشترین', 'پایانی', 'میانگین معاملاتی', 'وضعیت');

    private static function row_values($row) {
        return array($row['date'], $row['weekday'], $row['low'], $row['high'], $row['close'], $row['average'], $row['status']);
    }

    /** @param array $series_list each: ['symbol' => ..., 'rows' => ..., 'stats' => ...] */
    public static function to_csv($series_list) {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_merge(array('نماد'), self::COLUMNS));
        foreach ($series_list as $series) {
            foreach ($series['rows'] as $row) {
                fputcsv($handle, array_merge(array($series['symbol']['name']), self::row_values($row)));
            }
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return "\xEF\xBB\xBF" . $csv; // BOM so Excel opens it as UTF-8
    }

    /** Quick date-range shortcuts offered in the sidebar, e.g. "۱ ماه". */
    public static function range_presets($today) {
        list($ty, $tm, $td) = $today;
        $end = GC_Jalali::format($ty, $tm, $td);
        $back = function ($days) use ($ty, $tm, $td) {
            list($y, $m, $d) = GC_Jalali::add_days($ty, $tm, $td, -$days);
            return GC_Jalali::format($y, $m, $d);
        };
        $presets = array(
            array('id' => '7', 'label' => '۷ روز', 'start' => $back(6)),
            array('id' => '30', 'label' => '۱ ماه', 'start' => $back(29)),
            array('id' => '90', 'label' => '۳ ماه', 'start' => $back(89)),
            array('id' => '180', 'label' => '۶ ماه', 'start' => $back(179)),
            array('id' => '365', 'label' => '۱ سال', 'start' => $back(364)),
            array('id' => 'month', 'label' => 'این ماه', 'start' => GC_Jalali::format($ty, $tm, 1)),
            array('id' => 'year', 'label' => 'از ابتدای سال', 'start' => GC_Jalali::format($ty, 1, 1)),
        );
        foreach ($presets as &$p) {
            $p['end'] = $end;
        }
        return $presets;
    }

    public static function to_json_payload($series_list, $start, $end, $app_name, $version) {
        $series_out = array();
        foreach ($series_list as $series) {
            $series_out[] = array(
                'symbol' => GC_Symbols::to_dict($series['symbol']),
                'rows' => $series['rows'],
                'stats' => $series['stats'],
            );
        }
        return array(
            'app' => $app_name,
            'version' => $version,
            'range' => array(
                'start' => GC_Jalali::format($start[0], $start[1], $start[2]),
                'end' => GC_Jalali::format($end[0], $end[1], $end[2]),
            ),
            'series' => $series_out,
        );
    }
}
