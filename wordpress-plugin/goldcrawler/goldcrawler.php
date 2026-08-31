<?php
/**
 * Plugin Name:       GoldCrawler — کراولر قیمت
 * Plugin URI:        https://github.com/mmhdih/PriceCeawler
 * Description:       گزارش روزانه قیمت طلا، سکه، ارز و رمزارز از TGJU با رابط کاربری فارسی. با شورت‌کد [gold_crawler] در هر صفحه یا پست (از جمله صفحات ساخته‌شده با المنتور) قابل استفاده است.
 * Version:           1.0.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            PriceCeawler contributors
 * License:           MIT
 * Text Domain:       goldcrawler
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GOLDCRAWLER_VERSION', '1.0.4');
define('GOLDCRAWLER_FILE', __FILE__);
define('GOLDCRAWLER_DIR', plugin_dir_path(__FILE__));
define('GOLDCRAWLER_URL', plugin_dir_url(__FILE__));

require_once GOLDCRAWLER_DIR . 'includes/class-gc-jalali.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-symbols.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-tgju.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-report.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-xlsx.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-storage.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-crawler.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-license.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-admin.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-ajax.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-shortcode.php';
require_once GOLDCRAWLER_DIR . 'includes/class-gc-cron.php';

register_activation_hook(GOLDCRAWLER_FILE, array('GC_Cron', 'activate'));
register_deactivation_hook(GOLDCRAWLER_FILE, array('GC_Cron', 'deactivate'));

add_action('init', function () {
    GC_Ajax::register();
    GC_Shortcode::register();
    GC_Cron::register();
});

if (is_admin()) {
    GC_Admin::register();
}
