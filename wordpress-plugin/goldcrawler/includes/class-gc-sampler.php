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
        return self::sample_now($settings['symbols']);
    }

    /** @param string[]|null $keys symbols to sample; defaults to the watched list */
    public static function sample_now($keys = null) {
        $settings = GC_Storage::get_settings();
        $keys = $keys ?: $settings['symbols'];

        $recorded = array();
        $errors = array();

        foreach (GC_Crawler::resolve((array) $keys) as $symbol) {
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
