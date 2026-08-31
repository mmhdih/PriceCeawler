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

    /** @return array<string,array> key => symbol dict (catalog + custom_symbols from settings) */
    public static function known_symbols() {
        $symbols = GC_Symbols::catalog();
        $settings = GC_Storage::get_settings();
        foreach ((array) $settings['custom_symbols'] as $entry) {
            $key = isset($entry['key']) ? trim((string) $entry['key']) : '';
            if ($key !== '' && !isset($symbols[$key])) {
                $symbols[$key] = GC_Symbols::custom($key, $entry['name'] ?? null, $entry['currency'] ?? 'IRR');
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
