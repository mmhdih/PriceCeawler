<?php
/**
 * The [gold_crawler] shortcode: renders the dashboard and enqueues its
 * assets. Gated behind the same GC_License check as the AJAX endpoints, so
 * a visitor without access sees a styled "gate" screen instead of a UI shell
 * whose buttons would all silently fail.
 *
 * A logged-out visitor gets an inline login/registration form (handled by
 * GC_Auth over AJAX) right on this same page - no redirect to a separate
 * login page, so the visitor never has to leave the shortcode page to sign
 * in or create an account.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GC_Shortcode {

    private static $assets_enqueued = false;
    private static $gate_styles_enqueued = false;
    private static $auth_assets_enqueued = false;

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
                'برای مشاهده قیمت لحظه‌ای طلا، سکه و ارز و دریافت گزارش، وارد حساب کاربری خود شوید؛ اگر هنوز حساب ندارید، همین‌جا می‌توانید ثبت‌نام کنید.',
                true
            );
        } else {
            return self::render_gate(
                'warn',
                'شما هنوز به این ابزار دسترسی ندارید',
                'حساب شما فعال است، اما مجوز استفاده از این ابزار هنوز برایتان فعال نشده. برای دریافت دسترسی، با مدیر سایت تماس بگیرید.',
                false
            );
        }

        self::enqueue_assets();

        ob_start();
        include GOLDCRAWLER_DIR . 'includes/template-app.php';
        return ob_get_clean();
    }

    /**
     * @param string $icon      'lock' or 'warn'
     * @param string $title
     * @param string $desc
     * @param bool   $show_auth  render the inline login/register forms below the message
     */
    private static function render_gate($icon, $title, $desc, $show_auth) {
        self::enqueue_gate_styles();

        $icon_markup = $icon === 'lock'
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16h.01"/></svg>';

        return '<div class="goldcrawler-app goldcrawler-app--gate" dir="rtl">'
            . '<div class="gate">'
            . '<div class="gate__icon gate__icon--' . esc_attr($icon) . '" aria-hidden="true">' . $icon_markup . '</div>'
            . '<h2 class="gate__title">' . esc_html($title) . '</h2>'
            . '<p class="gate__desc">' . esc_html($desc) . '</p>'
            . ($show_auth ? self::render_auth_forms() : '')
            . '</div></div>';
    }

    private static function render_auth_forms() {
        self::enqueue_auth_assets();
        $registration_enabled = GC_Auth::registration_enabled();

        $tabs = '';
        if ($registration_enabled) {
            $tabs = '<div class="gate__tabs" role="tablist">'
                . '<button type="button" class="gate__tab is-active" data-tab="login">ورود</button>'
                . '<button type="button" class="gate__tab" data-tab="register">ثبت‌نام</button>'
                . '</div>';
        }

        $login_form = '<form class="gate__form" id="goldcrawlerLoginForm" data-form="login">'
            . '<label class="gate__field"><span>نام کاربری یا ایمیل</span>'
            . '<input type="text" name="username" autocomplete="username" required></label>'
            . '<label class="gate__field"><span>رمز عبور</span>'
            . '<input type="password" name="password" autocomplete="current-password" required></label>'
            . '<button type="submit" class="btn btn--primary gate__submit">ورود</button>'
            . '<a class="gate__forgot" href="' . esc_url(wp_lostpassword_url()) . '">رمز عبور را فراموش کرده‌اید؟</a>'
            . '</form>';

        $register_form = '';
        if ($registration_enabled) {
            $register_form = '<form class="gate__form" id="goldcrawlerRegisterForm" data-form="register" hidden>'
                . '<label class="gate__field"><span>نام کاربری</span>'
                . '<input type="text" name="username" autocomplete="username" required></label>'
                . '<label class="gate__field"><span>ایمیل</span>'
                . '<input type="email" name="email" autocomplete="email" required></label>'
                . '<label class="gate__field"><span>رمز عبور</span>'
                . '<input type="password" name="password" autocomplete="new-password" minlength="6" required></label>'
                . '<button type="submit" class="btn btn--primary gate__submit">ثبت‌نام</button>'
                . '</form>';
        }

        return '<div class="gate__auth">'
            . $tabs
            . $login_form
            . $register_form
            . '<p class="gate__message" id="goldcrawlerAuthMessage" role="alert" hidden></p>'
            . '</div>';
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

    private static function enqueue_auth_assets() {
        if (self::$auth_assets_enqueued) {
            return;
        }
        self::$auth_assets_enqueued = true;

        wp_enqueue_script(
            'goldcrawler-gate',
            GOLDCRAWLER_URL . 'assets/gate.js',
            array(), GOLDCRAWLER_VERSION, true
        );
        wp_localize_script('goldcrawler-gate', 'GoldCrawlerAuthConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(GC_Auth::NONCE_ACTION),
        ));
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
