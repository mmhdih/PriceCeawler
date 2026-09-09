<?php
/**
 * Records the live price of the watched symbols into GC_Intraday.
 *
 * The TGJU history endpoint returns one row per day, but the row for *today*
 * carries the current price in its close column, so sampling that row every
 * few minutes builds a real intraday series - which is the only way to get
 * 10-minute / hourly / per-change prices at all, since no daily endpoint can
 * be asked about the minutes that have already passed.
 *
 * Fetching goes through GC_Crawler::points_for($symbol, force) so it reuses
 * the same parser and error handling as the rest of the plugin; the cache is
 * bypassed on purpose (a cached row would just re-record the same value).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GC_Sampler {

    const HOOK = 'goldcrawler_intraday_sample';
    const SCHEDULE = 'goldcrawler_ten_minutes';
    const INTERVAL_SECONDS = 600;

    public static function register() {
        add_filter('cron_schedules', array(__CLASS__, 'add_schedule'));
        add_action(self::HOOK, array(__CLASS__, 'run'));
    }

    /** WordPress ships no 10-minute interval, so declare one. */
    public static function add_schedule($schedules) {
        if (!is_array($schedules)) {
            $schedules = array();
        }
        $schedules[self::SCHEDULE] = array(
            'interval' => self::INTERVAL_SECONDS,
            'display' => 'هر ۱۰ دقیقه (GoldCrawler)',
        );
        return $schedules;
    }

    /**
     * Schedules the 10-minute tick only while recording is switched on, so a
     * site that never enables intraday never gets a background job at all.
     * Safe to call on every settings save - it only acts on a real change.
     */
    public static function sync_schedule($enabled) {
        $scheduled = wp_next_scheduled(self::HOOK) !== false;
        if ($enabled && !$scheduled) {
            wp_schedule_event(time(), self::SCHEDULE, self::HOOK);
        } elseif (!$enabled && $scheduled) {
            wp_clear_scheduled_hook(self::HOOK);
        }
        return (bool) $enabled;
    }

    public static function activate() {
        $settings = GC_Storage::get_settings();
        self::sync_schedule(!empty($settings['intraday_recording']));
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * One sampling pass over the watched symbols. A no-op unless the site
     * owner turned intraday recording on, so nothing is ever written to the
     * uploads folder behind their back.
     *
     * @return array{recorded: string[], errors: array[], pruned: int}|null
     */
    public static function run() {
        $settings = GC_Storage::get_settings();
        if (empty($settings['intraday_recording'])) {
            return null;
        }
        // The scheduler records what the *administrator* chose, not whatever
        // symbols a visitor last picked for their own report - those are two
        // different decisions, and only one of them is site-wide.
        return self::sample_now(self::scheduled_symbols($settings));
    }

    /**
     * Symbol keys the scheduled recorder samples.
     *
     * Falls back to the site's watched list when the admin has not narrowed
     * it, so enabling recording without touching anything else still works.
     *
     * @return string[]
     */
    public static function scheduled_symbols($settings = null) {
        $settings = $settings === null ? GC_Storage::get_settings() : $settings;
        $chosen = isset($settings['sampler_symbols']) ? (array) $settings['sampler_symbols'] : array();
        // Trim before filtering: a whitespace-only key is not a symbol, and
        // would make the cron request a nameless URL on every fire.
        $chosen = array_map('trim', array_map('strval', $chosen));
        $chosen = array_values(array_filter($chosen, 'strlen'));
        if ($chosen) {
            return $chosen;
        }
        return isset($settings['symbols']) ? (array) $settings['symbols'] : array();
    }

    /**
     * Store every intraday point TGJU currently publishes for one symbol.
     *
     * Returns the number of newly stored points, or 0 when the chart source
     * has nothing for it (in which case the caller records the live price
     * instead). Never throws: a harvest failure must not stop the scheduler
     * from sampling the remaining symbols.
     */
    private static function harvest($symbol) {
        $settings = GC_Storage::get_settings();
        if (isset($settings['intraday_source']) && $settings['intraday_source'] === 'recorded') {
            return 0;   // the admin asked us not to touch the chart source
        }
        $now = time();
        try {
            $fetched = GC_TGJU_Intraday::fetch_candles(
                $symbol,
                // A day and a half back: the page carries today (and often
                // yesterday), and re-storing a point we already have is free.
                $now - (36 * 3600),
                $now,
                GC_TGJU_Intraday::endpoints_from_setting(
                    isset($settings['intraday_endpoint']) ? $settings['intraday_endpoint'] : ''
                ),
                GC_TGJU_Intraday::resolutions_from_setting(
                    isset($settings['intraday_native']) ? $settings['intraday_native'] : ''
                )
            );
        } catch (Exception $exc) {
            return 0;
        }

        $points = array();
        foreach ($fetched['candles'] as $candle) {
            $price = $candle['close'] !== null ? $candle['close'] : $candle['open'];
            if ($price !== null) {
                $points[(int) $candle['ts']] = (float) $price;
            }
        }
        return GC_Intraday::record_many($symbol['key'], $points);
    }

    /** @param string[]|null $keys symbols to sample; defaults to the watched list */
    public static function sample_now($keys = null) {
        $settings = GC_Storage::get_settings();
        $keys = $keys ?: self::scheduled_symbols($settings);

        $recorded = array();
        $errors = array();

        foreach (GC_Crawler::resolve((array) $keys) as $symbol) {
            // Prefer harvesting TGJU's own intraday series for the whole day.
            // Its profile page carries every 10-minute point of today, so one
            // request captures the complete day - which means a missed cron
            // fire leaves no gap, and the stored history is TGJU's own data
            // rather than an artefact of when we happened to sample.
            $harvested = self::harvest($symbol);
            if ($harvested > 0) {
                $recorded[] = $symbol['key'];
                continue;
            }

            // Otherwise fall back to recording the single current price.
            try {
                list($points, $from_cache) = GC_Crawler::points_for($symbol, true);
                // points_for() deliberately falls back to cached data when the
                // network fails ("stale data beats no data") - which is right
                // for a report, but wrong here: recording a stale price under a
                // fresh timestamp would invent an observation that never
                // happened. An intraday sample must be a real reading.
                if ($from_cache) {
                    $errors[] = array(
                        'symbol' => $symbol['key'], 'name' => $symbol['name'],
                        'message' => 'قیمت تازه از TGJU دریافت نشد؛ برای جلوگیری از ثبت داده نادرست، نمونه‌ای ذخیره نشد.',
                    );
                    continue;
                }
            } catch (GC_Tgju_Exception $e) {
                $errors[] = array('symbol' => $symbol['key'], 'name' => $symbol['name'], 'message' => $e->getMessage());
                continue;
            } catch (Exception $e) {
                $errors[] = array('symbol' => $symbol['key'], 'name' => $symbol['name'], 'message' => 'خطای پیش‌بینی‌نشده: ' . $e->getMessage());
                continue;
            }

            $price = self::latest_price($points);
            if ($price === null) {
                $errors[] = array(
                    'symbol' => $symbol['key'], 'name' => $symbol['name'],
                    'message' => 'قیمت جاری در پاسخ TGJU پیدا نشد.',
                );
                continue;
            }
            if (GC_Intraday::record($symbol['key'], $price)) {
                $recorded[] = $symbol['key'];
            }
        }

        $pruned = GC_Intraday::prune();
        GC_Storage::update_settings(array('last_sample' => time()));

        return array('recorded' => $recorded, 'errors' => $errors, 'pruned' => $pruned);
    }

    /** Close of the most recent day in the parsed history = the current price. */
    private static function latest_price($points) {
        if (!is_array($points) || !$points) {
            return null;
        }
        ksort($points);
        $last = end($points);
        if (!is_array($last) || !isset($last['close']) || $last['close'] === null) {
            return null;
        }
        return (float) $last['close'] > 0 ? (float) $last['close'] : null;
    }
}
