<?php
/**
 * Daily automatic crawl via WP-Cron.
 *
 * WP-Cron only fires on a page visit (WordPress has no background daemon by
 * default), so on a low-traffic site this can run late in the day. For a
 * guaranteed daily time, add a real server Cron Job that requests
 * wp-cron.php once a day - see the "کراول خودکار روزانه" section of
 * readme.txt. Either way this hook is what actually does the work.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GC_Cron {

    const HOOK = 'goldcrawler_daily_crawl';

    public static function register() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
    }

    public static function activate() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'daily', self::HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run() {
        GC_Crawler::maybe_daily_crawl();
    }
}
