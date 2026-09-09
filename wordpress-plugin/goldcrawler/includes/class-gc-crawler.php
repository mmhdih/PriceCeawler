<?php
/**
 * Coordinates fetching, caching and archiving of TGJU price data.
 *
 * Fetches are sequential (not parallel like the desktop app's thread pool):
 * PHP on typical shared hosting has no safe concurrency primitive for this,
 * and a handful of symbols fetched one after another is fine for a
 * request-triggered "دریافت داده‌ها" click.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GC_Crawler {

    /**
     * @return array<string,array> key => symbol dict: GC_Symbols::catalog()
     * minus whatever the admin disabled from Settings ← GoldCrawler, plus
     * custom_symbols from settings (admin-added there, or by any licensed
     * user from the sidebar's "add custom symbol" box - same shared list).
     */
    public static function known_symbols() {
        $settings = GC_Storage::get_settings();
        $disabled = array_flip((array) $settings['disabled_symbols']);

        $symbols = array();
        foreach (GC_Symbols::catalog() as $key => $def) {
            if (!isset($disabled[$key])) {
                $symbols[$key] = $def;
            }
        }

        foreach ((array) $settings['custom_symbols'] as $entry) {
            $key = isset($entry['key']) ? trim((string) $entry['key']) : '';
            if ($key !== '' && !isset($symbols[$key])) {
                $symbols[$key] = GC_Symbols::custom(
                    $key, $entry['name'] ?? null, $entry['currency'] ?? 'IRR',
                    $entry['group'] ?? null, $entry['decimals'] ?? null
                );
            }
        }
        return $symbols;
    }

    /** @param string[] $keys @return array[] resolved symbol dicts, de-duplicated, blanks dropped */
    public static function resolve($keys) {
        $known = self::known_symbols();
        $resolved = array();
        $seen = array();
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $resolved[] = isset($known[$key]) ? $known[$key] : GC_Symbols::custom($key);
        }
        return $resolved;
    }

    /** @return array{0: array, 1: bool} [points_by_date, served_from_cache] */
    public static function points_for($symbol, $force = false) {
        list($cached_points, $fetched_at) = GC_Storage::read_cache($symbol['key']);
        $fresh_enough = $cached_points !== null && (microtime(true) - $fetched_at) < GC_Storage::CACHE_TTL_SECONDS;

        if ($cached_points !== null && !$force && $fresh_enough) {
            return array($cached_points, true);
        }

        try {
            $points = GC_Tgju::fetch($symbol);
        } catch (GC_Tgju_Exception $e) {
            if ($cached_points !== null) {
                return array($cached_points, true); // stale data beats no data
            }
            throw $e;
        }

        GC_Storage::write_cache($symbol['key'], $points);
        return array($points, false);
    }

    /**
     * @param string[] $keys
     * @param array $start [y,m,d]
     * @param array $end   [y,m,d]
     * @return array{series: array[], errors: array[], fromCache: string[]}
     */
    public static function build($keys, $start, $end, $fill_gaps = true, $force = false) {
        $symbols = self::resolve($keys);
        $series = array();
        $errors = array();
        $from_cache = array();

        foreach ($symbols as $symbol) {
            try {
                list($points, $cached) = self::points_for($symbol, $force);
            } catch (GC_Tgju_Exception $e) {
                $errors[] = array('symbol' => $symbol['key'], 'name' => $symbol['name'], 'message' => $e->getMessage());
                continue;
            } catch (Exception $e) {
                $errors[] = array('symbol' => $symbol['key'], 'name' => $symbol['name'], 'message' => 'خطای پیش‌بینی‌نشده: ' . $e->getMessage());
                continue;
            }

            if ($cached) {
                $from_cache[] = $symbol['key'];
            }
            $built = GC_Report::build_series($symbol, $points, $start, $end, $fill_gaps);
            $series[] = array('symbol' => $symbol, 'rows' => $built['rows'], 'stats' => $built['stats']);

            // Keep only a bounded recent window in the archive per fetch,
            // mirroring the desktop app (full history still lives in $points
            // for the report itself; the archive is the long-term record).
            $tail = array_slice($points, -400, null, true);
            GC_Storage::merge_archive($symbol['key'], $tail);
        }

        return array('series' => $series, 'errors' => $errors, 'fromCache' => $from_cache);
    }

    /**
     * Build series at whatever resolution the request asked for.
     *
     * Daily goes to TGJU (which only serves daily rows); the intraday
     * resolutions are served from our own recorded samples, since no amount
     * of asking the daily endpoint can recover the last ten minutes.
     *
     * @return array{series: array[], errors: array[], fromCache: string[], resolution: string}
     */
    public static function build_at($keys, $start, $end, $fill_gaps = true, $force = false, $resolution = 'daily') {
        $resolution = GC_Intraday::normalise_resolution($resolution);
        if (!GC_Intraday::is_intraday($resolution)) {
            return array_merge(self::build($keys, $start, $end, $fill_gaps, $force), array('resolution' => $resolution));
        }

        $series = array();
        $errors = array();
        $settings = GC_Storage::get_settings();
        $recording = !empty($settings['intraday_recording']);
        $source = isset($settings['intraday_source']) ? $settings['intraday_source'] : 'auto';
        $endpoints = GC_TGJU_Intraday::endpoints_from_setting(
            isset($settings['intraday_endpoint']) ? $settings['intraday_endpoint'] : ''
        );
        $natives = GC_TGJU_Intraday::resolutions_from_setting(
            isset($settings['intraday_native']) ? $settings['intraday_native'] : ''
        );
        list($from_ts, $to_ts) = GC_Intraday::range_bounds($start, $end);

        foreach (self::resolve($keys) as $symbol) {
            $rows = array();
            $fetch_error = '';

            // Extraction first: it can answer for any past range, whereas
            // recorded samples only cover time the sampler was running for.
            if ($source === 'auto' || $source === 'tgju') {
                try {
                    $fetched = GC_TGJU_Intraday::fetch_candles(
                        $symbol, $from_ts, $to_ts, $endpoints, $natives
                    );
                    $rows = GC_Intraday::aggregate_candles(
                        $fetched['candles'], $resolution, $symbol['decimals']
                    );
                    if ($rows) {
                        // Remember what worked so later requests skip probing.
                        self::pin_intraday_endpoint(
                            $fetched['endpoint'], $fetched['resolution'], $settings
                        );
                    }
                } catch (GC_TGJU_Intraday_Error $exc) {
                    $fetch_error = $exc->getMessage();
                }
            }

            if (!$rows && ($source === 'auto' || $source === 'recorded')) {
                $built = GC_Intraday::build_series($symbol, $start, $end, $resolution);
                $rows = $built['rows'];
            }

            if (!$rows) {
                // Say why there is nothing and what to do about it - "no data"
                // alone leaves the user with no next step.
                if ($source === 'recorded') {
                    $message = GC_Intraday::explain_empty($symbol, $start, $end, $recording);
                } elseif ($fetch_error !== '') {
                    $message = '«' . $symbol['name'] . '»: ' . $fetch_error;
                } else {
                    $message = 'برای «' . $symbol['name']
                        . '» در این بازه داده درون‌روزی از TGJU دریافت نشد.';
                }
                $errors[] = array(
                    'symbol' => $symbol['key'], 'name' => $symbol['name'], 'message' => $message,
                );
                continue;
            }

            $series[] = array(
                'symbol' => $symbol,
                'rows' => $rows,
                'stats' => GC_Intraday::stats($rows, $symbol),
            );
        }
        return array('series' => $series, 'errors' => $errors, 'fromCache' => array(), 'resolution' => $resolution);
    }

    /** Persist the chart endpoint that answered, so we stop probing. */
    private static function pin_intraday_endpoint($name, $native, $settings) {
        $current = isset($settings['intraday_endpoint']) ? $settings['intraday_endpoint'] : '';
        $current_native = isset($settings['intraday_native']) ? $settings['intraday_native'] : '';
        $changes = array();
        // A user-supplied URL template is theirs to keep; never churn it.
        $keep_url = ($name === 'custom' || strpos($current, '{symbol}') !== false);
        if (!$keep_url && $current !== $name) {
            $changes['intraday_endpoint'] = $name;
        }
        if ($native !== '' && $current_native !== $native) {
            $changes['intraday_native'] = $native;
        }
        if (!$changes) {
            return;
        }
        GC_Storage::update_settings($changes);
    }

    /** Report which TGJU chart endpoint this host can actually reach. */
    public static function probe_intraday($keys = null) {
        $settings = GC_Storage::get_settings();
        $keys = $keys ? $keys : (isset($settings['symbols']) ? $settings['symbols'] : array('geram18'));
        $resolved = self::resolve(array_slice((array) $keys, 0, 1));
        if (!$resolved) {
            $resolved = self::resolve(array('geram18'));
        }
        $symbol = $resolved[0];
        $report = GC_TGJU_Intraday::probe($symbol);

        $working = null;
        foreach ($report as $row) {
            if (!empty($row['ok'])) {
                $working = $row;
                break;
            }
        }
        if ($working) {
            self::pin_intraday_endpoint(
                $working['endpoint'],
                isset($working['resolution']) ? $working['resolution'] : '',
                $settings
            );
        }
        return array('symbol' => $symbol['key'], 'working' => $working, 'attempts' => $report);
    }

    public static function daily_crawl($keys = null) {
        $settings = GC_Storage::get_settings();
        $keys = $keys ?: $settings['symbols'];
        list($today_y, $today_m, $today_d) = GC_Jalali::today();
        $today = GC_Jalali::format($today_y, $today_m, $today_d);

        $added = array();
        $errors = array();
        foreach (self::resolve($keys) as $symbol) {
            try {
                list($points, ) = self::points_for($symbol, true);
            } catch (GC_Tgju_Exception $e) {
                $errors[] = array('symbol' => $symbol['key'], 'name' => $symbol['name'], 'message' => $e->getMessage());
                continue;
            }
            $added[$symbol['key']] = GC_Storage::merge_archive($symbol['key'], $points);
        }

        if ($added) {
            GC_Storage::update_settings(array('last_crawl' => $today));
        }
        return array('date' => $today, 'added' => $added, 'errors' => $errors, 'archive' => GC_Storage::archive_summary());
    }

    /** Runs from WP-Cron once a day; a no-op if already crawled today. */
    public static function maybe_daily_crawl() {
        $settings = GC_Storage::get_settings();
        if (!$settings['auto_crawl']) {
            return null;
        }
        list($ty, $tm, $td) = GC_Jalali::today();
        if ($settings['last_crawl'] === GC_Jalali::format($ty, $tm, $td)) {
            return null;
        }
        return self::daily_crawl();
    }
}
