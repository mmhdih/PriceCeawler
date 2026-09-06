<?php
/**
 * Inline login/registration for the logged-out gate screen. Deliberately
 * separate from GC_Ajax: these two actions are the ONLY wp_ajax_nopriv_*
 * handlers in the whole plugin (everything else stays intentionally
 * unreachable by logged-out visitors) and they must never touch any
 * GoldCrawler data - they only authenticate against WordPress's own user
 * system, exactly like wp-login.php does, so the visitor never has to leave
 * this page to sign in or create an account.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GC_Auth {

    const NONCE_ACTION = 'goldcrawler_auth';

    public static function register() {
        add_action('wp_ajax_nopriv_goldcrawler_login', array(__CLASS__, 'handle_login'));
        add_action('wp_ajax_goldcrawler_login', array(__CLASS__, 'handle_login'));
        add_action('wp_ajax_nopriv_goldcrawler_register', array(__CLASS__, 'handle_register'));
        add_action('wp_ajax_goldcrawler_register', array(__CLASS__, 'handle_register'));
    }

    /** WooCommerce sites commonly allow account creation regardless of the core "anyone can register" toggle. */
    public static function registration_enabled() {
        return (bool) get_option('users_can_register') || function_exists('WC');
    }

    private static function body() {
        $raw = GC_Ajax::$test_body_override !== null ? GC_Ajax::$test_body_override : file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : array();
    }

    private static function guard_nonce() {
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? $_SERVER['HTTP_X_WP_NONCE'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(array('message' => 'نشست شما منقضی شده است؛ صفحه را دوباره بارگذاری کنید.'), 403);
        }
    }

    public static function handle_login() {
        self::guard_nonce();
        $payload = self::body();
        $login = trim((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? ''); // never sanitize a password - it can contain any character

        if ($login === '' || $password === '') {
            wp_send_json_error(array('message' => 'نام کاربری یا ایمیل و رمز عبور را وارد کنید.'));
        }

        $user = wp_signon(array(
            'user_login' => $login,
            'user_password' => $password,
            'remember' => true,
        ), is_ssl());

        if (is_wp_error($user)) {
            wp_send_json_error(array('message' => 'نام کاربری/ایمیل یا رمز عبور اشتباه است.'));
        }
        wp_send_json_success(array('message' => 'با موفقیت وارد شدید.'));
    }

    public static function handle_register() {
        self::guard_nonce();
        if (!self::registration_enabled()) {
            wp_send_json_error(array('message' => 'ثبت‌نام کاربر تازه در حال حاضر در این سایت غیرفعال است.'));
        }

        $payload = self::body();
        $username = sanitize_user(wp_unslash((string) ($payload['username'] ?? '')), true);
        $email = sanitize_email(wp_unslash((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');

        if ($username === '' || $email === '' || $password === '') {
            wp_send_json_error(array('message' => 'همه فیلدها را پر کنید.'));
        }
        if (!is_email($email)) {
            wp_send_json_error(array('message' => 'نشانی ایمیل معتبر نیست.'));
        }
        if (strlen($password) < 6) {
            wp_send_json_error(array('message' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.'));
        }
        if (username_exists($username)) {
            wp_send_json_error(array('message' => 'این نام کاربری قبلاً استفاده شده است.'));
        }
        if (email_exists($email)) {
            wp_send_json_error(array('message' => 'حسابی با این ایمیل قبلاً ثبت‌نام کرده است.'));
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            wp_send_json_error(array('message' => $user_id->get_error_message()));
        }

        // Sign the freshly-created account in immediately, same as the real
        // wp-login.php?action=register flow does after a successful signup.
        wp_signon(array(
            'user_login' => $username,
            'user_password' => $password,
            'remember' => true,
        ), is_ssl());

        wp_send_json_success(array('message' => 'ثبت‌نام شما با موفقیت انجام شد.'));
    }
}
