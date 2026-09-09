<?php
/**
 * Extract intraday candles from TGJU for a requested time range.
 *
 * This is the *extraction* path: ask TGJU for the prices of a past range at a
 * sub-daily resolution and hand them back. It is what you want when you need
 * "the last three days at 10-minute precision" right now, as opposed to
 * GC_Sampler, which can only ever tell you about time that already passed
 * while it was running.
 *
 * TGJU's charts are drawn by a TradingView charting library, which speaks the
 * UDF (universal data feed) protocol:
 *
 *   GET <base>/history?symbol=<sym>&resolution=<r>&from=<unix>&to=<unix>
 *   -> {"s":"ok","t":[...],"o":[...],"h":[...],"l":[...],"c":[...]}
 *
 * That endpoint is not a documented, contractual API, so the URL is *data
 * driven*: candidates() lists the shapes we know how to try, the admin can
 * override it with their own URL template, and probe() reports which one this
 * host can actually reach. Nothing guesses silently - when no candidate
 * answers, the caller gets an error naming every attempt.
 *
 * Only the finest resolution TGJU will serve is fetched; the 10-minute,
 * hourly and per-change rows are then produced by GC_Intraday's own
 * aggregation, so one code path decides bucketing for extracted and recorded
 * data alike.
 */

if (!defined('ABSPATH') && !defined('GC_STANDALONE_TEST')) {
    exit;
}

final class GC_TGJU_Intraday {

    /**
     * Resolution codes to try, finest first. Standard TradingView UDF uses
     * bare minute counts, but this endpoint is undocumented and observably
     * ignores values it does not recognise (answering with DAILY bars rather
     * than an error), so several spellings are tried and the *returned*
     * spacing decides which one actually gave intraday data.
     */
    const NATIVE_RESOLUTIONS = array(
        '1', '5', '10', '15', '30', '60',
        '1m', '5m', '10m', '15m', '30m', '60m',
        '1min', '5min', '60min', '1H',
    );

    /** Bars spaced a day apart are daily bars, whatever was asked for. */
    const DAY_SECONDS = 86400;

    /**
     * Spacing must be at least this much finer than a day to count as
     * intraday. Half a day is deliberately loose: a feed with long market
     * gaps can still be genuinely intraday, and the aggregation layer copes
     * with sparse buckets.
     */
    const MAX_INTRADAY_SPACING = 43200;

    /** Chart backends cap how much history one request may span. */
    const MAX_SPAN_SECONDS = 604800; // 7 days

    /** A hard stop, so a very wide range cannot spin forever. */
    const MAX_WINDOWS = 60;

    const TIMEOUT = 25;

    /**
     * Ordered by how likely each is to be the live endpoint.
     *
     * @return array[] each: name, url, symbol_style, parser, referer
     */
    public static function candidates() {
        return array(
            array(
                'name' => 'platform-tvdata',
                'url' => 'https://platform.tgju.org/fa/tvdata/history?symbol={symbol}&resolution={resolution}&from={from}&to={to}',
                'symbol_style' => 'key',
                'parser' => 'udf',
                'referer' => 'https://platform.tgju.org/',
            ),
            array(
                'name' => 'platform-tvdata-upper',
                'url' => 'https://platform.tgju.org/fa/tvdata/history?symbol={symbol}&resolution={resolution}&from={from}&to={to}',
                'symbol_style' => 'upper_underscore',
                'parser' => 'udf',
                'referer' => 'https://platform.tgju.org/',
            ),
            array(
                'name' => 'api-tvdata',
                'url' => 'https://api.tgju.org/v1/tvdata/history?symbol={symbol}&resolution={resolution}&from={from}&to={to}',
                'symbol_style' => 'key',
                'parser' => 'udf',
                'referer' => 'https://www.tgju.org/',
            ),
            array(
                'name' => 'api-chart-intraday',
                'url' => 'https://api.tgju.org/v1/market/indicator/chart-data/{symbol}?resolution={resolution}&from={from}&to={to}',
                'symbol_style' => 'key',
                'parser' => 'rows',
                'referer' => 'https://www.tgju.org/',
            ),
        );
    }

    /**
     * Resolve the intraday_endpoint setting into the endpoints to try.
     *
     * Empty means "try every candidate". A candidate's name pins it, so a
     * site that already discovered the working one stops probing. A value
     * containing {symbol} is a full URL template the admin supplied - the
     * escape hatch for when none of our candidates is right, so a wrong guess
     * never needs a code change.
     */
    /**
     * Try a previously working resolution code first, then the rest.
     *
     * It stays a *preference*, not a lock: the code is still validated by the
     * granularity check, so a feed that changes behaviour falls through to
     * the other spellings instead of silently serving daily bars again.
     */
    public static function resolutions_from_setting($pinned) {
        $pinned = is_string($pinned) ? trim($pinned) : '';
        if ($pinned === '' || !in_array($pinned, self::NATIVE_RESOLUTIONS, true)) {
            return self::NATIVE_RESOLUTIONS;
        }
        $rest = array_values(array_filter(
            self::NATIVE_RESOLUTIONS,
            function ($r) use ($pinned) { return $r !== $pinned; }
        ));
        return array_merge(array($pinned), $rest);
    }

    public static function endpoints_from_setting($pinned) {
        $pinned = is_string($pinned) ? trim($pinned) : '';
        if ($pinned === '') {
            return self::candidates();
        }
        if (strpos($pinned, '{symbol}') !== false) {
            return array(array(
                'name' => 'custom',
                'url' => $pinned,
                // A hand-pasted URL usually already carries the exact symbol
                // spelling the backend wants.
                'symbol_style' => 'key',
                'parser' => (strpos($pinned, 'tvdata') !== false || strpos($pinned, 'history') !== false)
                    ? 'udf' : 'rows',
                'referer' => 'https://www.tgju.org/',
            ));
        }
        foreach (self::candidates() as $endpoint) {
            if ($endpoint['name'] === $pinned) {
                return array($endpoint);
            }
        }
        return self::candidates();
    }

    private static function symbol_for($symbol_key, $style) {
        if ($style === 'upper') {
            return strtoupper($symbol_key);
        }
        if ($style === 'upper_underscore') {
            return strtoupper(str_replace('-', '_', $symbol_key));
        }
        return $symbol_key;
    }

    public static function build_url($endpoint, $symbol_key, $resolution, $from, $to) {
        return str_replace(
            array('{symbol}', '{resolution}', '{from}', '{to}'),
            array(
                rawurlencode(self::symbol_for($symbol_key, $endpoint['symbol_style'])),
                rawurlencode((string) $resolution),
                (string) (int) $from,
                (string) (int) $to,
            ),
            $endpoint['url']
        );
    }

    // -- parsing (pure: no network) -------------------------------------------

    /** A price in the display unit, or null when unusable. */
    private static function number($value, $divisor) {
        if ($value === null || is_bool($value) || is_array($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number) || $number == 0) {
            return null;
        }
        return $number / $divisor;
    }

    /**
     * Parse TradingView's UDF history shape.
     *
     * s:"no_data" is a valid empty answer - the range simply has no bars - so
     * it yields an empty array rather than an error. s:"error" is a failure.
     *
     * @return array[] each: ts, open, high, low, close
     * @throws GC_TGJU_Intraday_Error
     */
    public static function parse_udf($payload, $symbol) {
        if (!is_array($payload)) {
            throw new GC_TGJU_Intraday_Error('پاسخ نمودار درون‌روزی TGJU قابل خواندن نیست.');
        }

        $status = isset($payload['s']) ? strtolower((string) $payload['s']) : '';
        if ($status === 'error') {
            $detail = '';
            foreach (array('errmsg', 'error') as $key) {
                if (!empty($payload[$key]) && is_string($payload[$key])) {
                    $detail = trim($payload[$key]);
                    break;
                }
            }
            throw new GC_TGJU_Intraday_Error(
                'سرویس نمودار TGJU خطا برگرداند' . ($detail !== '' ? ": {$detail}" : '.')
            );
        }
        if ($status === 'no_data') {
            return array();
        }
        if ($status !== '' && $status !== 'ok') {
            throw new GC_TGJU_Intraday_Error("وضعیت ناشناخته «{$status}» از سرویس نمودار TGJU.");
        }
        if (!isset($payload['t']) || !is_array($payload['t'])) {
            throw new GC_TGJU_Intraday_Error('سرویس نمودار TGJU فهرست زمان‌ها را برنگرداند.');
        }

        $divisor = GC_Symbols::divisor($symbol['currency']);
        $column = function ($name) use ($payload) {
            return (isset($payload[$name]) && is_array($payload[$name])) ? $payload[$name] : array();
        };
        $opens = $column('o');
        $highs = $column('h');
        $lows = $column('l');
        $closes = $column('c');

        $at = function ($values, $index) use ($divisor) {
            return array_key_exists($index, $values)
                ? self::number($values[$index], $divisor) : null;
        };

        $candles = array();
        foreach ($payload['t'] as $index => $raw_ts) {
            if (!is_numeric($raw_ts)) {
                continue;
            }
            $ts = (int) $raw_ts;
            if ($ts <= 0) {
                continue;
            }
            // Some feeds send milliseconds; anything past the year 5000 is one.
            if ($ts > 100000000000) {
                $ts = intdiv($ts, 1000);
            }
            $candle = array(
                'ts' => $ts,
                'open' => $at($opens, $index),
                'high' => $at($highs, $index),
                'low' => $at($lows, $index),
                'close' => $at($closes, $index),
            );
            if ($candle['close'] === null && $candle['open'] === null) {
                continue; // a bar with no price at all is not an observation
            }
            $candles[$ts] = $candle;
        }
        ksort($candles);
        return array_values($candles);
    }

    /**
     * Parse a row-per-bar shape: {"data": [[ts, o, h, l, c], ...]}, or rows
     * keyed by name - a second endpoint shape is why this parser exists.
     */
    public static function parse_rows($payload, $symbol) {
        $rows = null;
        if (is_array($payload)) {
            foreach (array('data', 'rows', 'candles') as $key) {
                if (isset($payload[$key]) && is_array($payload[$key])) {
                    $rows = $payload[$key];
                    break;
                }
            }
            if ($rows === null && array_key_exists(0, $payload)) {
                $rows = $payload;
            }
        }
        if (!is_array($rows)) {
            throw new GC_TGJU_Intraday_Error('فهرست داده‌های نمودار در پاسخ TGJU یافت نشد.');
        }

        $divisor = GC_Symbols::divisor($symbol['currency']);
        $candles = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists(0, $row)) {
                $raw_ts = $row[0];
                $values = array(
                    array_key_exists(1, $row) ? $row[1] : null,
                    array_key_exists(2, $row) ? $row[2] : null,
                    array_key_exists(3, $row) ? $row[3] : null,
                    array_key_exists(4, $row) ? $row[4] : null,
                );
            } else {
                $pick = function ($names) use ($row) {
                    foreach ($names as $name) {
                        if (isset($row[$name])) {
                            return $row[$name];
                        }
                    }
                    return null;
                };
                $raw_ts = $pick(array('t', 'time', 'timestamp', 'date'));
                $values = array(
                    $pick(array('o', 'open')), $pick(array('h', 'high')),
                    $pick(array('l', 'low')), $pick(array('c', 'close')),
                );
            }

            if (!is_numeric($raw_ts)) {
                continue;
            }
            $ts = (int) (float) $raw_ts;
            if ($ts <= 0) {
                continue;
            }
            if ($ts > 100000000000) {
                $ts = intdiv($ts, 1000);
            }

            $candle = array(
                'ts' => $ts,
                'open' => self::number($values[0], $divisor),
                'high' => self::number($values[1], $divisor),
                'low' => self::number($values[2], $divisor),
                'close' => self::number($values[3], $divisor),
            );
            if ($candle['close'] === null && $candle['open'] === null) {
                continue;
            }
            $candles[$ts] = $candle;
        }
        ksort($candles);
        return array_values($candles);
    }

    private static function parse($endpoint, $payload, $symbol) {
        return $endpoint['parser'] === 'rows'
            ? self::parse_rows($payload, $symbol)
            : self::parse_udf($payload, $symbol);
    }

    // -- network ---------------------------------------------------------------

    /** Split a range into request-sized windows, oldest first. */
    public static function windows($from, $to, $span) {
        $windows = array();
        $start = (int) $from;
        $end = (int) $to;
        $guard = 0;
        while ($start <= $end && $guard < self::MAX_WINDOWS) {
            $stop = min($start + $span, $end);
            $windows[] = array($start, $stop);
            if ($stop >= $end) {
                break;
            }
            $start = $stop + 1;
            $guard++;
        }
        return $windows;
    }

    private static function request($url, $referer) {
        $response = wp_remote_get($url, array(
            'timeout' => self::TIMEOUT,
            'headers' => array(
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Accept-Language' => 'fa,en;q=0.8',
                'Referer' => $referer,
            ),
        ));
        if (is_wp_error($response)) {
            throw new GC_TGJU_Intraday_Error($response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new GC_TGJU_Intraday_Error("سرویس نمودار TGJU کد {$code} برگرداند.");
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            throw new GC_TGJU_Intraday_Error('پاسخ سرویس نمودار TGJU یک JSON معتبر نبود.');
        }
        return $decoded;
    }

    /**
     * Median seconds between consecutive bars, or null with fewer than two.
     *
     * The median, not the mean: an overnight or weekend gap would drag a
     * mean up past a day and make genuinely intraday data look daily.
     *
     * @param array[] $candles sorted by ts
     */
    public static function median_gap($candles) {
        if (count($candles) < 2) {
            return null;
        }
        $gaps = array();
        for ($i = 1; $i < count($candles); $i++) {
            $gap = (int) $candles[$i]['ts'] - (int) $candles[$i - 1]['ts'];
            if ($gap > 0) {
                $gaps[] = $gap;
            }
        }
        if (!$gaps) {
            return null;
        }
        sort($gaps);
        $middle = intdiv(count($gaps), 2);
        if (count($gaps) % 2) {
            return $gaps[$middle];
        }
        return intdiv($gaps[$middle - 1] + $gaps[$middle], 2);
    }

    /**
     * Whether these bars are actually finer than daily.
     *
     * This endpoint accepts an unrecognised resolution and answers with
     * daily bars rather than an error, so "I got rows" is not evidence of
     * intraday data. Without this check a daily series would be relabelled
     * as 10-minute rows - a wrong answer, which is worse than no answer.
     *
     * A single bar carries no spacing, so it is judged by its timestamp: a
     * daily bar sits exactly on a day boundary (00:00 UTC for this feed).
     */
    public static function is_intraday_spacing($candles) {
        $gap = self::median_gap($candles);
        if ($gap !== null) {
            return $gap <= self::MAX_INTRADAY_SPACING;
        }
        if (count($candles) === 1) {
            return ((int) $candles[0]['ts'] % self::DAY_SECONDS) !== 0;
        }
        return false;
    }

    private static function fetch_one($endpoint, $symbol, $resolution, $from, $to) {
        $candles = array();
        foreach (self::windows($from, $to, self::MAX_SPAN_SECONDS) as $window) {
            $url = self::build_url($endpoint, $symbol['key'], $resolution, $window[0], $window[1]);
            $payload = self::request($url, $endpoint['referer']);
            foreach (self::parse($endpoint, $payload, $symbol) as $candle) {
                if ($candle['ts'] >= $from && $candle['ts'] <= $to) {
                    $candles[$candle['ts']] = $candle;
                }
            }
        }
        ksort($candles);
        return array_values($candles);
    }

    /**
     * Fetch the finest intraday candles available for a range.
     *
     * @return array{candles: array[], endpoint: string, resolution: string}
     * @throws GC_TGJU_Intraday_Error naming every attempt when nothing works
     */
    public static function fetch_candles($symbol, $from, $to, $endpoints = null, $resolutions = null) {
        if ($to < $from) {
            throw new GC_TGJU_Intraday_Error('بازه درخواستی نامعتبر است (پایان قبل از شروع).');
        }
        $endpoints = $endpoints ? $endpoints : self::candidates();
        $resolutions = $resolutions ? $resolutions : self::NATIVE_RESOLUTIONS;
        $attempts = array();
        $saw_daily = false;

        foreach ($endpoints as $endpoint) {
            foreach ($resolutions as $resolution) {
                try {
                    $candles = self::fetch_one($endpoint, $symbol, $resolution, $from, $to);
                } catch (GC_TGJU_Intraday_Error $exc) {
                    $attempts[] = $endpoint['name'] . "/{$resolution}: " . $exc->getMessage();
                    continue;
                }
                if (!$candles) {
                    $attempts[] = $endpoint['name'] . "/{$resolution}: بدون داده";
                    continue;
                }
                // Rows are not enough: this endpoint answers an unrecognised
                // resolution with DAILY bars instead of erroring, and
                // relabelling those as 10-minute rows would be a wrong answer.
                if (!self::is_intraday_spacing($candles)) {
                    $saw_daily = true;
                    $gap = self::median_gap($candles);
                    $attempts[] = $endpoint['name'] . "/{$resolution}: داده روزانه"
                        . ($gap ? ' (فاصله ' . intdiv($gap, 3600) . ' ساعت)' : '');
                    continue;
                }
                return array(
                    'candles' => $candles,
                    'endpoint' => $endpoint['name'],
                    'resolution' => $resolution,
                );
            }
        }

        if ($saw_daily) {
            throw new GC_TGJU_Intraday_Error(
                'سرویس نمودار TGJU برای این نماد فقط داده روزانه برگرداند و هیچ‌کدام از'
                . ' دقت‌های درون‌روزی را نپذیرفت؛ پس گزارش ۱۰ دقیقه/۱ ساعت از این منبع'
                . ' ساخته نمی‌شود. آدرس درست سرویس درون‌روزی را از DevTools بردارید و در'
                . ' تنظیمات وارد کنید. تلاش‌ها: ' . implode('؛ ', array_slice($attempts, 0, 6))
            );
        }
        throw new GC_TGJU_Intraday_Error(
            'سرویس نمودار درون‌روزی TGJU پاسخ قابل استفاده‌ای نداد. تلاش‌ها: '
            . implode('؛ ', array_slice($attempts, 0, 6))
        );
    }

    /**
     * Try every candidate and report what each did. This turns "the chart
     * endpoint is undocumented" into one button whose output says exactly
     * which URL works from this host.
     */
    public static function probe($symbol, $hours = 48) {
        $to = time();
        $from = $to - ($hours * 3600);
        $report = array();

        foreach (self::candidates() as $endpoint) {
            foreach (self::NATIVE_RESOLUTIONS as $resolution) {
                $row = array(
                    'endpoint' => $endpoint['name'],
                    'resolution' => $resolution,
                    'url' => self::build_url($endpoint, $symbol['key'], $resolution, $from, $to),
                    'ok' => false,
                );
                try {
                    $candles = self::fetch_one($endpoint, $symbol, $resolution, $from, $to);
                    $row['candles'] = count($candles);
                    if ($candles) {
                        $row['spacing_seconds'] = self::median_gap($candles);
                        $row['intraday'] = self::is_intraday_spacing($candles);
                        $last = $candles[count($candles) - 1];
                        $row['sample_close'] = $last['close'];
                        // Only genuinely sub-daily data counts as working;
                        // daily bars are reported so the output shows why.
                        $row['ok'] = $row['intraday'];
                        if (!$row['intraday']) {
                            $row['error'] = 'داده روزانه، نه درون‌روزی';
                        }
                    }
                } catch (Exception $exc) { // a probe must report, never throw
                    $row['error'] = $exc->getMessage();
                }
                $report[] = $row;
                if ($row['ok']) {
                    return $report; // first success is enough; stop hammering
                }
            }
        }
        return $report;
    }
}

class GC_TGJU_Intraday_Error extends RuntimeException {
}
