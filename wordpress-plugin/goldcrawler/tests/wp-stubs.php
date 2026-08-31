<?php
/**
 * Minimal stand-ins for the WordPress functions this plugin calls, just
 * enough to exercise GC_Storage / GC_Crawler / GC_Ajax / GC_Shortcode under
 * plain `php` on the CLI, without a real WordPress install. NOT shipped in
 * the plugin zip - test-only, mirroring how the Python app's tests stub
 * network calls instead of hitting the real TGJU API.
 */

define('ABSPATH', '/tmp/');

$GLOBALS['gc_test_upload_dir'] = sys_get_temp_dir() . '/gc-wp-uploads-' . getmypid();
$GLOBALS['gc_test_options'] = array();
$GLOBALS['gc_test_actions'] = array();
$GLOBALS['gc_test_remote_get_responses'] = array(); // url => callable|array
$GLOBALS['gc_test_cron_scheduled'] = array();
$GLOBALS['gc_test_current_user_can'] = true; // legacy blanket override: true=any capability granted, false=none
$GLOBALS['gc_test_user_role'] = null; // set to 'logged_out'|'subscriber'|'administrator' for granular per-capability checks
$GLOBALS['gc_test_current_user_id'] = 42;
$GLOBALS['gc_test_is_admin'] = false; // simulates being inside wp-admin (for is_admin())
$GLOBALS['gc_test_users'] = array(); // populated by tests via gc_test_add_user()
$GLOBALS['gc_test_last_json'] = null; // captured wp_send_json_* payload

function wp_upload_dir() {
    return array('basedir' => $GLOBALS['gc_test_upload_dir'], 'baseurl' => 'http://example.test/uploads');
}

function wp_mkdir_p($dir) {
    return is_dir($dir) || mkdir($dir, 0777, true);
}

function trailingslashit($s) { return rtrim($s, '/') . '/'; }

function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }

function wp_generate_password($length = 12, $special = true) {
    return substr(bin2hex(random_bytes(16)), 0, $length);
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }

class WP_Error {
    private $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

/** Test hook: register a canned response (or exception-throwing callable) for a URL. */
function gc_test_stub_remote_get($url_substring, $handler) {
    $GLOBALS['gc_test_remote_get_responses'][$url_substring] = $handler;
}

function wp_remote_get($url, $args = array()) {
    foreach ($GLOBALS['gc_test_remote_get_responses'] as $needle => $handler) {
        if (strpos($url, $needle) !== false) {
            return is_callable($handler) ? $handler($url, $args) : $handler;
        }
    }
    return new WP_Error('http_request_failed', 'اتصال آزمایشی برای این آدرس تعریف نشده است.');
}

function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }

function home_url($path = '') { return 'http://example.test' . $path; }
function admin_url($path = '') { return 'http://example.test/wp-admin/' . ltrim($path, '/'); }

function current_user_can($capability) {
    $role = $GLOBALS['gc_test_user_role'];
    if ($role !== null) {
        // A simplified but faithful model of WordPress's real roles: every
        // logged-in account (Subscriber and up) holds 'read'; only
        // Administrator holds 'manage_options'.
        if ($role === 'logged_out') return false;
        if ($role === 'subscriber') return $capability === 'read';
        if ($role === 'administrator') return true;
    }
    return $GLOBALS['gc_test_current_user_can'];
}

function is_user_logged_in() {
    if ($GLOBALS['gc_test_user_role'] !== null) {
        return $GLOBALS['gc_test_user_role'] !== 'logged_out';
    }
    return true;
}

function get_current_user_id() {
    return is_user_logged_in() ? $GLOBALS['gc_test_current_user_id'] : 0;
}

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['gc_test_options']) ? $GLOBALS['gc_test_options'][$name] : $default;
}

function update_option($name, $value) {
    $GLOBALS['gc_test_options'][$name] = $value;
    return true;
}

/** Test helper: register a fake WP_User-like object for get_users(). */
function gc_test_add_user($id, $display_name, $login, $email, $roles = array('subscriber')) {
    $GLOBALS['gc_test_users'][] = (object) array(
        'ID' => $id, 'display_name' => $display_name, 'user_login' => $login,
        'user_email' => $email, 'roles' => $roles,
    );
}

function get_users($args = array()) { return $GLOBALS['gc_test_users']; }

function is_admin() { return $GLOBALS['gc_test_is_admin']; }

function add_options_page(...$args) { $GLOBALS['gc_test_actions']['options_pages'][] = $args; }

function wp_nonce_field($action, $name = '_wpnonce', $referer = true, $echo = true) {
    $field = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '">';
    if ($echo) { echo $field; }
    return $field;
}

function wp_die($message = '') {
    throw new RuntimeException('wp_die: ' . (is_string($message) ? $message : 'died'));
}

function wp_create_nonce($action) { return 'test-nonce-' . md5($action); }
function wp_verify_nonce($nonce, $action) { return $nonce === wp_create_nonce($action) ? 1 : false; }

function check_ajax_referer($action, $field = false, $die = true) {
    $nonce = isset($_REQUEST[$field]) ? $_REQUEST[$field] : '';
    $ok = wp_verify_nonce($nonce, $action);
    if (!$ok && $die) {
        wp_send_json_error(array('message' => 'nonce failed'), 403);
    }
    return $ok;
}

function wp_send_json($data, $status_code = null) {
    $GLOBALS['gc_test_last_json'] = array('status' => $status_code ?: 200, 'data' => $data);
    throw new GC_Test_JsonSent(); // stop execution like wp_die() would, for the test harness to catch
}
function wp_send_json_success($data = null, $status_code = null) {
    wp_send_json(array('success' => true, 'data' => $data), $status_code);
}
function wp_send_json_error($data = null, $status_code = null) {
    wp_send_json(array('success' => false, 'data' => $data), $status_code ?: 400);
}
class GC_Test_JsonSent extends Exception {}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['gc_test_actions'][$hook][] = $callback;
}
function do_action($hook, ...$args) {
    foreach ($GLOBALS['gc_test_actions'][$hook] ?? array() as $cb) {
        call_user_func_array($cb, $args);
    }
}
function add_shortcode($tag, $callback) { $GLOBALS['gc_test_actions']['shortcode_' . $tag][] = $callback; }

function register_activation_hook($file, $callback) { $GLOBALS['gc_test_actions']['activate'][] = $callback; }
function register_deactivation_hook($file, $callback) { $GLOBALS['gc_test_actions']['deactivate'][] = $callback; }

function wp_next_scheduled($hook) { return $GLOBALS['gc_test_cron_scheduled'][$hook] ?? false; }
function wp_schedule_event($timestamp, $recurrence, $hook) { $GLOBALS['gc_test_cron_scheduled'][$hook] = $timestamp; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['gc_test_cron_scheduled'][$hook]); }

function plugin_dir_path($file) { return rtrim(dirname($file), '/') . '/'; }
function plugin_dir_url($file) { return 'http://example.test/wp-content/plugins/goldcrawler/'; }
function plugins_url($path = '', $file = '') { return 'http://example.test/wp-content/plugins/goldcrawler/' . ltrim($path, '/'); }

function wp_enqueue_style(...$args) {}
function wp_enqueue_script(...$args) {}
function wp_localize_script(...$args) {}
function wp_register_style(...$args) {}
function wp_register_script(...$args) {}

function nocache_headers() {}

function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return $s; }
function __($text, $domain = 'default') { return $text; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
