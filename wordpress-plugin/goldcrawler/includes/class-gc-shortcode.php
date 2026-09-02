<?php
/**
 * The [gold_crawler] shortcode: renders the dashboard and enqueues its
 * assets. Gated behind the same GC_License check as the AJAX endpoints, so
 * a visitor without access sees a styled "gate" screen instead of a UI shell
 * whose buttons would all silently fail.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GC_Shortcode {

    // The site's own login/register page (WooCommerce "My account" style).
    // Hardcoded because this plugin is built for this one site, not
    // distributed generally; a filter still lets it be overridden if needed.
    const DEFAULT_ACCOUNT_URL = 'https://tavoosweb.ir/my-account/';

    private static $assets_enqueued = false;
    private static $gate_styles_enqueued = false;

    public static function register() {
        add_shortcode('gold_crawler', array(__CLASS__, 'render'));
    }

    public static function render($atts = array()) {
        if (GC_License::current_user_allowed()) {
            // fall through to the real app below
        } elseif (!is_user_logged_in()) {
            return self::render_gate(
                'lock',
                'برای استفاده از این ابزار وارد شوید',
                'برای مشاهده قیمت لحظه‌ای طلا، سکه و ارز و دریافت گزارش، ابتدا وارد حساب کاربری خود در سایت شوید؛ اگر هنوز حساب ندارید، از همین‌جا می‌توانید ثبت‌نام کنید.',
                self::account_url(),
                'ورود یا ثبت‌نام'
            );
        } else {
            return self::render_gate(
                'warn',
                'شما هنوز به این ابزار دسترسی ندارید',
                'حساب شما فعال است، اما مجوز استفاده از این ابزار هنوز برایتان فعال نشده. برای دریافت دسترسی، با مدیر سایت تماس بگیرید.'
            );
        }

        self::enqueue_assets();

        ob_start();
        include GOLDCRAWLER_DIR . 'includes/template-app.php';
        return ob_get_clean();
    }

    private static function account_url() {
        return apply_filters('goldcrawler_account_url', self::DEFAULT_ACCOUNT_URL);
    }

    /**
     * @param string      $icon    'lock' or 'warn'
     * @param string      $title
     * @param string      $desc
     * @param string|null $cta_url  omit for no call-to-action button
     * @param string|null $cta_label
     */
    private static function render_gate($icon, $title, $desc, $cta_url = null, $cta_label = null) {
        self::enqueue_gate_styles();

        $icon_markup = $icon === 'lock'
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16h.01"/></svg>';

        $cta_markup = '';
        if ($cta_url) {
            $cta_markup = '<a class="btn btn--primary gate__cta" href="' . esc_url($cta_url) . '">'
                . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg>'
                . esc_html($cta_label) . '</a>';
        }

        return '<div class="goldcrawler-app goldcrawler-app--gate" dir="rtl">'
            . '<div class="gate">'
            . '<div class="gate__icon gate__icon--' . esc_attr($icon) . '" aria-hidden="true">' . $icon_markup . '</div>'
            . '<h2 class="gate__title">' . esc_html($title) . '</h2>'
            . '<p class="gate__desc">' . esc_html($desc) . '</p>'
            . $cta_markup
            . '</div></div>';
    }

    private static function enqueue_gate_styles() {
        if (self::$gate_styles_enqueued) {
            return; // idempotent: safe to call from both the gate and the full app
        }
        self::$gate_styles_enqueued = true;

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
    }

    private static function enqueue_assets() {
        if (self::$assets_enqueued) {
            return; // more than one [gold_crawler] on a page shares one set of assets
        }
        self::$assets_enqueued = true;

        self::enqueue_gate_styles();
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
