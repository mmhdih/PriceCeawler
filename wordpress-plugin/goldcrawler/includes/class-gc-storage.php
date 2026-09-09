<?php
/**
 * Settings, cache and archive storage under wp-content/uploads/goldcrawler/.
 *
 * Deliberately file-based (not wp_options) so a growing daily archive never
 * bloats the autoloaded options table; only the uploads directory needs to
 * be writable, which WordPress already requires for media uploads.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GC_Storage {

    const CACHE_TTL_SECONDS = 900; // 15 minutes

    private static $default_settings = array(
        'symbols' => array('geram18', 'sekee', 'price_dollar_rl'),
        'custom_symbols' => array(),
        'disabled_symbols' => array(), // built-in GC_Symbols::catalog() keys the admin turned off
        'range_preset' => '30',
        'start' => '',
        'end' => '',
        'fill_gaps' => true,
        // Off by default: no unattended background fetching unless the site
        // owner explicitly opts in from the sidebar toggle. Every "دریافت
        // داده‌ها" / export click already fetches live data on its own, so
        // this only controls the *unattended, once-a-day* WP-Cron crawl.
        'auto_crawl' => false,
        'theme' => 'light',
        'last_crawl' => '',
        // Intraday (10-minute / hourly / per-change) prices have to be
        // sampled as time passes - the TGJU history endpoint only ever
        // returns one row per day. Off by default for the same reason
        // auto_crawl is: no unattended writing to uploads/ unless asked.
        'intraday_recording' => false,
        // Where intraday rows come from. "auto" asks TGJU's chart service
        // first and only falls back to locally recorded samples; "tgju" and
        // "recorded" force one source so a failure stays visible instead of
        // being silently papered over by the other.
        'intraday_source' => 'auto',
        // Pinned chart endpoint: a candidate name, or a full URL template
        // with {symbol}/{resolution}/{from}/{to}. Empty = probe candidates.
        'intraday_endpoint' => '',
        // The chart resolution code that actually returned sub-daily bars.
        // Pinned after a success so later requests do not re-try every
        // spelling; the granularity check still validates what comes back.
        'intraday_native' => '',
        // Which symbols the scheduled recorder samples. Empty = the site's
        // watched list. Admin-only, because it decides what the site's own
        // cron does on every fire.
        'sampler_symbols' => array(),
        // How many days of recorded samples to keep before pruning.
        'retention_days' => 30,
        'resolution' => 'daily',
        'last_sample' => 0,
    );

    public static function base_dir() {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'goldcrawler';
        wp_mkdir_p($dir . '/archive');
        wp_mkdir_p($dir . '/cache');
        // uploads/ is served publicly by WordPress; keep this data out of it.
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir;
    }

    private static function read_json($path, $default = null) {
        if (!is_file($path)) {
            return $default;
        }
        $data = json_decode(file_get_contents($path), true);
        return json_last_error() === JSON_ERROR_NONE ? $data : $default;
    }

    private static function write_json($path, $payload) {
        wp_mkdir_p(dirname($path));
        $tmp = $path . '.tmp-' . wp_generate_password(6, false);
        file_put_contents($tmp, wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $path); // atomic on the same filesystem, mirrors the Python app's os.replace()
    }

    // -- settings ---------------------------------------------------------

    public static function get_settings() {
        $stored = self::read_json(self::base_dir() . '/settings.json', array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $settings = self::$default_settings;
        foreach ($stored as $key => $value) {
            if (array_key_exists($key, self::$default_settings)) {
                $settings[$key] = $value;
            }
        }
        return $settings;
    }

    /**
     * Settings only a site administrator may change.
     *
     * These are not per-user preferences: they decide what the site's own
     * scheduler does, where data comes from, and how long it is kept. A
     * licensed front-end user picking symbols for their own report must not
     * be able to reach them.
     */
    const ADMIN_ONLY_SETTINGS = array(
        'intraday_recording',
        'sampler_symbols',
        'retention_days',
        'intraday_source',
        'intraday_endpoint',
        'intraday_native',
        'auto_crawl',
        'disabled_symbols',
    );

    public static function is_admin_only($key) {
        return in_array($key, self::ADMIN_ONLY_SETTINGS, true);
    }

    /**
     * @param array $values      settings to merge in
     * @param bool  $is_admin    whether the caller may change admin-only keys;
     *                           false silently drops them rather than failing
     *                           the whole save, so a normal user's own
     *                           preferences still persist.
     */
    public static function update_settings($values, $is_admin = true) {
        $settings = self::get_settings();
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::$default_settings)) {
                continue;
            }
            if (!$is_admin && self::is_admin_only($key)) {
                continue;
            }
            $settings[$key] = $value;
        }
        self::write_json(self::base_dir() . '/settings.json', $settings);
        return $settings;
    }

    /** Retention window for recorded samples, clamped to something sane. */
    public static function retention_days() {
        $settings = self::get_settings();
        $days = isset($settings['retention_days']) ? (int) $settings['retention_days'] : 30;
        if ($days < 1) {
            $days = 1;      // "keep nothing" would delete today's own samples
        }
        if ($days > 3650) {
            $days = 3650;   // ten years is already far past any useful report
        }
        return $days;
    }

    // -- cache --------------------------------------------------------------

    /** Public because GC_Intraday stores its own files keyed by the same symbol keys. */
    public static function safe_filename($key) {
        // Same character class GC_Symbols::is_valid_custom_key() enforces
        // upstream (letters/digits/._-), so a legitimate key round-trips
        // exactly back to the same filename. ".." is still neutralised here
        // too, as a second line of defence against path traversal.
        $key = preg_replace('/[^A-Za-z0-9_.-]/', '_', $key);
        $key = str_replace('..', '_', $key);
        return trim($key, '.') ?: '_';
    }

    /** @return array{0: array|null, 1: float} [points_by_date_or_null, fetched_at_unix] */
    public static function read_cache($symbol_key) {
        $path = self::base_dir() . '/cache/' . self::safe_filename($symbol_key) . '.json';
        $payload = self::read_json($path);
        if (!is_array($payload) || !isset($payload['points'])) {
            return array(null, 0.0);
        }
        return array($payload['points'], (float) $payload['fetched_at']);
    }

    public static function write_cache($symbol_key, $points) {
        $path = self::base_dir() . '/cache/' . self::safe_filename($symbol_key) . '.json';
        self::write_json($path, array('symbol' => $symbol_key, 'fetched_at' => microtime(true), 'points' => $points));
    }

    // -- archive --------------------------------------------------------------

    public static function load_archive($symbol_key) {
        $path = self::base_dir() . '/archive/' . self::safe_filename($symbol_key) . '.json';
        $data = self::read_json($path, array());
        return is_array($data) ? $data : array();
    }

    /** @param array $points date => row (as returned by GC_Tgju::parse_rows) */
    public static function merge_archive($symbol_key, $points) {
        $existing = self::load_archive($symbol_key);
        $added = 0;
        foreach ($points as $date => $row) {
            if (!isset($existing[$date])) {
                $added++;
            }
            $existing[$date] = $row;
        }
        ksort($existing);
        $path = self::base_dir() . '/archive/' . self::safe_filename($symbol_key) . '.json';
        self::write_json($path, $existing);
        return $added;
    }

    /** @return array{key:string, days:int, first:string, last:string}[] */
    public static function archive_summary() {
        $dir = self::base_dir() . '/archive';
        $rows = array();
        foreach (glob($dir . '/*.json') ?: array() as $path) {
            $data = self::read_json($path, array());
            if (!is_array($data) || !$data) {
                continue;
            }
            $dates = array_keys($data);
            sort($dates);
            $rows[] = array(
                'key' => basename($path, '.json'),
                'days' => count($dates),
                'first' => $dates[0],
                'last' => end($dates),
            );
        }
        return $rows;
    }
}
