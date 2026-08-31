<?php
/**
 * The [gold_crawler] shortcode: renders the dashboard and enqueues its
 * assets. Gated behind the same GC_License check as the AJAX endpoints, so
 * a visitor without access sees a polite notice instead of a UI shell whose
 * buttons would all silently fail.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GC_Shortcode {

    private static $assets_enqueued = false;

    public static function register() {
        add_shortcode('gold_crawler', array(__CLASS__, 'render'));
    }

    public static function render($atts = array()) {
        if (GC_License::current_user_allowed()) {
            // fall through to the real app below
        } elseif (!is_user_logged_in()) {
            return '<p class="goldcrawler-locked">برای مشاهده این ابزار باید وارد حساب کاربری خود در سایت شوید.</p>';
        } else {
            return '<p class="goldcrawler-locked">شما مجوز استفاده از این ابزار را ندارید. برای دریافت دسترسی با مدیر سایت تماس بگیرید.</p>';
        }

        self::enqueue_assets();

        ob_start();
        include GOLDCRAWLER_DIR . 'includes/template-app.php';
        return ob_get_clean();
    }

    private static function enqueue_assets() {
        if (self::$assets_enqueued) {
            return; // more than one [gold_crawler] on a page shares one set of assets
        }
        self::$assets_enqueued = true;

        wp_enqueue_style(
            'goldcrawler-vazirmatn',
            GOLDCRAWLER_URL . 'assets/fonts/vazirmatn.css',
            array(), GOLDCRAWLER_VERSION
        );
        wp_enqueue_style(
            'goldcrawler-styles',
            GOLDCRAWLER_URL . 'assets/styles.css',
            array('goldcrawler-vazirmatn'), GOLDCRAWLER_VERSION
        );
        wp_enqueue_script(
            'goldcrawler-app',
            GOLDCRAWLER_URL . 'assets/app.js',
            array(), GOLDCRAWLER_VERSION, true
        );
        wp_localize_script('goldcrawler-app', 'GoldCrawlerConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(GC_Ajax::NONCE_ACTION),
            'version' => GOLDCRAWLER_VERSION,
        ));
    }
}
