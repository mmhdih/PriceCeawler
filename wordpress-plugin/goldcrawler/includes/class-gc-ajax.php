<?php
/**
 * wp_ajax_* handlers behind admin-ajax.php.
 *
 * Every action here requires BOTH a logged-in user holding the plugin's
 * capability AND a valid nonce. CAPABILITY is 'read', which every logged-in
 * WordPress account holds regardless of role (Subscriber and up) - so this
 * is "any signed-in user", not "admins only". No wp_ajax_nopriv_* handlers
 * are registered at all, so a logged-out visitor gets WordPress's normal
 * silent "0" response with no code of ours ever running - only accounts,
 * never anonymous visitors, can reach this.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GC_Api_Exception extends Exception {
    public $status;
    public function __construct($message, $status = 400) {
        parent::__construct($message);
        $this->status = $status;
    }
}

final class GC_Ajax {

    const CAPABILITY = 'read';
    const NONCE_ACTION = 'goldcrawler_ajax';

    /**
     * Test-only seam: when set, body() returns this instead of reading
     * php://input (which can't be faked with a stream-wrapper override
     * without also breaking unrelated php://temp/php://memory usage
     * elsewhere, e.g. GC_Report::to_csv()). Always null in production.
     */
    public static $test_body_override = null;

    public static function register() {
        foreach (array('meta', 'archive', 'series', 'export', 'settings', 'symbols', 'crawl') as $action) {
            add_action('wp_ajax_goldcrawler_' . $action, array(__CLASS__, 'handle_' . $action));
        }
    }

    public static function can_use() {
        return current_user_can(self::CAPABILITY);
    }

    private static function guard() {
        if (!self::can_use()) {
            wp_send_json_error(array('message' => 'شما اجازه دسترسی به این ابزار را ندارید.'), 403);
        }
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? $_SERVER['HTTP_X_WP_NONCE'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(array('message' => 'نشست شما منقضی شده است؛ صفحه را دوباره بارگذاری کنید.'), 403);
        }
    }

    private static function body() {
        $raw = self::$test_body_override !== null ? self::$test_body_override : file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : array();
    }

    private static function symbol_keys($payload) {
        $keys = array();
        foreach ((array) ($payload['symbols'] ?? array()) as $k) {
            $k = trim((string) $k);
            if ($k !== '') {
                $keys[] = $k;
            }
        }
        if (!$keys) {
            throw new GC_Api_Exception('حداقل یک نماد را انتخاب کنید.');
        }
        if (count($keys) > 20) {
            throw new GC_Api_Exception('حداکثر ۲۰ نماد در هر گزارش پشتیبانی می‌شود.');
        }
        return $keys;
    }

    private static function parse_range($payload) {
        $start = GC_Jalali::parse($payload['start'] ?? '');
        $end = GC_Jalali::parse($payload['end'] ?? '');
        if ($start === null || $end === null) {
            throw new GC_Api_Exception('قالب تاریخ نامعتبر است؛ از قالب ۱۴۰۴/۰۱/۰۱ استفاده کنید.');
        }
        if (GC_Jalali::diff_days($start[0], $start[1], $start[2], $end[0], $end[1], $end[2]) < 0) {
            throw new GC_Api_Exception('تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.');
        }
        if (GC_Jalali::diff_days($start[0], $start[1], $start[2], $end[0], $end[1], $end[2]) > 3660) {
            throw new GC_Api_Exception('بازه انتخابی بیش از ۱۰ سال است؛ آن را کوتاه‌تر کنید.');
        }
        return array($start, $end);
    }

    // -- handlers -------------------------------------------------------

    public static function handle_meta() {
        self::guard();
        $today = GC_Jalali::today();
        list($ty, $tm, $td) = $today;
        wp_send_json_success(array(
            'app' => 'GoldCrawler',
            'titleFa' => 'کراولر قیمت',
            'version' => GOLDCRAWLER_VERSION,
            'today' => GC_Jalali::format($ty, $tm, $td),
            'todayLong' => GC_Jalali::weekday_name($ty, $tm, $td) . ' ' . $td . ' ' . GC_Jalali::month_name($tm) . ' ' . $ty,
            'symbols' => array_map(array('GC_Symbols', 'to_dict'), array_values(GC_Crawler::known_symbols())),
            'settings' => GC_Storage::get_settings(),
            'archive' => GC_Storage::archive_summary(),
            'presets' => GC_Report::range_presets($today),
        ));
    }

    public static function handle_archive() {
        self::guard();
        wp_send_json_success(array('archive' => GC_Storage::archive_summary()));
    }

    public static function handle_series() {
        self::guard();
        $payload = self::body();
        try {
            $keys = self::symbol_keys($payload);
            list($start, $end) = self::parse_range($payload);
        } catch (GC_Api_Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()), $e->status);
        }

        $result = GC_Crawler::build($keys, $start, $end, ($payload['fillGaps'] ?? true) !== false, !empty($payload['force']));
        if (!$result['series'] && $result['errors']) {
            wp_send_json_success(array_merge(array('error' => $result['errors'][0]['message']), $result), 200);
            return;
        }
        wp_send_json_success(array_merge(array(
            'range' => array(
                'start' => GC_Jalali::format($start[0], $start[1], $start[2]),
                'end' => GC_Jalali::format($end[0], $end[1], $end[2]),
            ),
        ), $result));
    }

    public static function handle_export() {
        self::guard();
        $payload = self::body();
        try {
            $keys = self::symbol_keys($payload);
            list($start, $end) = self::parse_range($payload);
            $format = strtolower((string) ($payload['format'] ?? 'xlsx'));
            if (!in_array($format, array('xlsx', 'csv', 'json'), true)) {
                throw new GC_Api_Exception('قالب خروجی پشتیبانی نمی‌شود.');
            }
        } catch (GC_Api_Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()), $e->status);
        }

        $result = GC_Crawler::build($keys, $start, $end, ($payload['fillGaps'] ?? true) !== false);
        if (!$result['series']) {
            $message = $result['errors'] ? $result['errors'][0]['message'] : 'داده‌ای برای خروجی وجود ندارد.';
            wp_send_json_error(array('message' => $message), 400);
        }

        $s = GC_Jalali::format($start[0], $start[1], $start[2]);
        $e = GC_Jalali::format($end[0], $end[1], $end[2]);
        $name = 'TGJU-' . str_replace('/', '-', $s) . '_' . str_replace('/', '-', $e) . '.' . $format;

        if ($format === 'xlsx') {
            $bytes = GC_Xlsx::build_report($result['series'], $start, $end, 'GoldCrawler', GOLDCRAWLER_VERSION);
            $content_type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } elseif ($format === 'csv') {
            $bytes = GC_Report::to_csv($result['series']);
            $content_type = 'text/csv; charset=utf-8';
        } else {
            $bytes = wp_json_encode(GC_Report::to_json_payload($result['series'], $start, $end, 'GoldCrawler', GOLDCRAWLER_VERSION), JSON_UNESCAPED_UNICODE);
            $content_type = 'application/json; charset=utf-8';
        }

        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Export-Warnings: ' . rawurlencode(wp_json_encode(array_column($result['errors'], 'message'))));
        echo $bytes;
        self::terminate(); // admin-ajax.php would otherwise append "0" after our binary body
    }

    /**
     * Real exit in production; in the test harness, throws instead so a
     * single test file can exercise more than one handler in one process.
     */
    private static function terminate() {
        if (defined('GC_STANDALONE_TEST')) {
            // A plain built-in exception, not a test-suite class name, so
            // production code never references anything test-only.
            throw new RuntimeException('gc-test-terminate');
        }
        exit;
    }

    public static function handle_settings() {
        self::guard();
        wp_send_json_success(array('settings' => GC_Storage::update_settings(self::body())));
    }

    public static function handle_symbols() {
        self::guard();
        $payload = self::body();
        $key = trim((string) ($payload['key'] ?? ''));
        if ($key === '' || !GC_Symbols::is_valid_custom_key($key)) {
            wp_send_json_error(array('message' => 'شناسه نماد فقط می‌تواند شامل حروف انگلیسی، عدد، «-»، «_» و «.» باشد.'), 400);
        }
        $name = trim((string) ($payload['name'] ?? '')) ?: $key;
        $currency = strtoupper((string) ($payload['currency'] ?? 'IRR')) === 'USD' ? 'USD' : 'IRR';

        $settings = GC_Storage::get_settings();
        $customs = array_values(array_filter($settings['custom_symbols'], fn($c) => ($c['key'] ?? '') !== $key));
        $customs[] = array('key' => $key, 'name' => $name, 'currency' => $currency);
        GC_Storage::update_settings(array('custom_symbols' => array_slice($customs, -50)));

        wp_send_json_success(array('symbols' => array_map(array('GC_Symbols', 'to_dict'), array_values(GC_Crawler::known_symbols()))));
    }

    public static function handle_crawl() {
        self::guard();
        $payload = self::body();
        $keys = !empty($payload['symbols']) ? $payload['symbols'] : null;
        wp_send_json_success(GC_Crawler::daily_crawl($keys));
    }
}
