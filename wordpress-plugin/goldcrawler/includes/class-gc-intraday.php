<?php
/**
 * Intraday resampling, plus optional local sampling of live prices.
 *
 * Two things live here. The important one is *resampling*: aggregate() and
 * aggregate_candles() turn observations into the resolution the user asked
 * for - 10-minute and hourly OHLC buckets, or one row per price change. Both
 * the candles extracted from TGJU (see GC_TGJU_Intraday) and any locally
 * recorded samples go through this same bucketing, so the two sources can
 * never disagree about where a bucket starts.
 *
 * The secondary one is a local sample store: record() appends a
 * (timestamp, price) observation per symbol, kept one file per symbol per day
 * (uploads/goldcrawler/intraday/<symbol>/<YYYY-MM-DD>.json) so a day is a
 * small read and retention pruning is just "delete files whose name is older
 * than N days". That store is a *fallback* for when the chart service cannot
 * be reached - it only ever covers time that already passed while sampling
 * was switched on, which is why extraction is preferred.
 *
 * All calendar/clock rendering is done in Tehran time, not the server's, so
 * a host in another timezone still buckets by the local trading clock.
 */

if (!defined('ABSPATH') && !defined('GC_STANDALONE_TEST')) {
    exit;
}

final class GC_Intraday {

    /** Iran has had no DST since 2022, so a fixed +03:30 is correct year-round. */
    const TEHRAN_OFFSET = 12600;

    const RES_10M = '10m';
    const RES_1H = '1h';
    const RES_TICK = 'tick';
    const RES_DAILY = 'daily';

    /** Bucket width in seconds; 'tick' has none (one row per observed change). */
    const BUCKET_SECONDS = array(self::RES_10M => 600, self::RES_1H => 3600);

    /** Day files older than this are pruned; keeps the uploads folder bounded. */
    const RETENTION_DAYS = 30;

    /** Hard cap per day file, so a runaway cron can never bloat one file. */
    const MAX_SAMPLES_PER_DAY = 2000;

    public static function resolutions() {
        return array(
            array('id' => self::RES_DAILY, 'label' => 'روزانه', 'intraday' => false),
            array('id' => self::RES_10M, 'label' => 'هر ۱۰ دقیقه', 'intraday' => true),
            array('id' => self::RES_1H, 'label' => 'هر ۱ ساعت', 'intraday' => true),
            array('id' => self::RES_TICK, 'label' => 'هر تغییر قیمت', 'intraday' => true),
        );
    }

    public static function is_intraday($resolution) {
        return in_array($resolution, array(self::RES_10M, self::RES_1H, self::RES_TICK), true);
    }

    public static function normalise_resolution($resolution) {
        $resolution = is_string($resolution) ? trim($resolution) : '';
        return self::is_intraday($resolution) ? $resolution : self::RES_DAILY;
    }

    // -- Tehran-local calendar helpers ---------------------------------------

    /** @return array{0:int,1:int,2:int} Tehran-local Gregorian [y, m, d] for a unix ts. */
    public static function local_gregorian($timestamp) {
        $shifted = (int) $timestamp + self::TEHRAN_OFFSET;
        return array(
            (int) gmdate('Y', $shifted),
            (int) gmdate('n', $shifted),
            (int) gmdate('j', $shifted),
        );
    }

    /** @return string ISO Tehran-local date, the day-file key. */
    public static function local_iso_date($timestamp) {
        return gmdate('Y-m-d', (int) $timestamp + self::TEHRAN_OFFSET);
    }

    /** @return string Tehran-local HH:MM. */
    public static function local_time($timestamp) {
        return gmdate('H:i', (int) $timestamp + self::TEHRAN_OFFSET);
    }

    /** @return string Jalali YYYY/MM/DD for the Tehran-local day of a unix ts. */
    public static function local_jalali_date($timestamp) {
        list($gy, $gm, $gd) = self::local_gregorian($timestamp);
        list($jy, $jm, $jd) = GC_Jalali::gregorian_to_jalali($gy, $gm, $gd);
        return GC_Jalali::format($jy, $jm, $jd);
    }

    /**
     * Start of the bucket a timestamp falls in, aligned to the Tehran clock
     * (so hourly buckets start on the local hour, not on a UTC hour that is
     * :30 past the local one).
     */
    public static function bucket_start($timestamp, $resolution) {
        $width = self::BUCKET_SECONDS[$resolution] ?? 0;
        if ($width <= 0) {
            return (int) $timestamp;
        }
        $local = (int) $timestamp + self::TEHRAN_OFFSET;
        return intdiv($local, $width) * $width - self::TEHRAN_OFFSET;
    }

    /** Inclusive unix range covering a Jalali start..end day pair, Tehran-local. */
    public static function range_bounds($start, $end) {
        list($sy, $sm, $sd) = GC_Jalali::jalali_to_gregorian($start[0], $start[1], $start[2]);
        list($ey, $em, $ed) = GC_Jalali::jalali_to_gregorian($end[0], $end[1], $end[2]);
        $from = gmmktime(0, 0, 0, $sm, $sd, $sy) - self::TEHRAN_OFFSET;
        $to = gmmktime(23, 59, 59, $em, $ed, $ey) - self::TEHRAN_OFFSET;
        return array($from, $to);
    }

    // -- storage --------------------------------------------------------------

    public static function base_dir() {
        $dir = GC_Storage::base_dir() . '/intraday';
        wp_mkdir_p($dir);
        return $dir;
    }

    private static function symbol_dir($symbol_key, $create = false) {
        $dir = self::base_dir() . '/' . GC_Storage::safe_filename($symbol_key);
        if ($create) {
            wp_mkdir_p($dir);
        }
        return $dir;
    }

    private static function day_path($symbol_key, $iso_date, $create = false) {
        return self::symbol_dir($symbol_key, $create) . '/' . $iso_date . '.json';
    }

    /**
     * Append one observed price. Samples within the same day file stay sorted
     * and de-duplicated by timestamp, so a double-fired cron is harmless.
     *
     * @return bool whether the sample was stored (false = same second already recorded)
     */
    public static function record($symbol_key, $price, $timestamp = null) {
        // NAN/INF pass both is_numeric() and a `<= 0` test, but json_encode
        // refuses them: the encode would return false, the day file would be
        // overwritten with nothing, and every earlier sample would be lost.
        if (!is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0) {
            return false;
        }
        $timestamp = $timestamp === null ? time() : (int) $timestamp;
        $iso = self::local_iso_date($timestamp);
        $path = self::day_path($symbol_key, $iso, true);

        $samples = self::read_day($path);
        if (isset($samples[$timestamp])) {
            return false;
        }
        $samples[$timestamp] = (float) $price;
        ksort($samples);
        if (count($samples) > self::MAX_SAMPLES_PER_DAY) {
            $samples = array_slice($samples, -self::MAX_SAMPLES_PER_DAY, null, true);
        }
        self::write_day($path, $symbol_key, $samples);
        return true;
    }

    /** @return array<int,float> timestamp => price */
    private static function read_day($path) {
        if (!is_file($path)) {
            return array();
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || empty($data['samples']) || !is_array($data['samples'])) {
            return array();
        }
        $samples = array();
        foreach ($data['samples'] as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            $ts = (int) $pair[0];
            $price = (float) $pair[1];
            if ($ts > 0 && $price > 0) {
                $samples[$ts] = $price;
            }
        }
        ksort($samples);
        return $samples;
    }

    private static function write_day($path, $symbol_key, $samples) {
        $pairs = array();
        foreach ($samples as $ts => $price) {
            $pairs[] = array($ts, $price);
        }
        $payload = array('symbol' => $symbol_key, 'samples' => $pairs);
        $encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            return; // keep the existing file rather than truncating it
        }
        // Same atomic write pattern GC_Storage uses: a crash mid-write must
        // never leave a truncated JSON file behind.
        $tmp = $path . '.tmp-' . wp_generate_password(6, false);
        file_put_contents($tmp, $encoded);
        rename($tmp, $path);
    }

    /** @return array<int,float> timestamp => price, across every day file in range */
    public static function load_samples($symbol_key, $from_ts, $to_ts) {
        $samples = array();
        $day = self::local_iso_date($from_ts);
        $last_day = self::local_iso_date($to_ts);
        $guard = 0;

        while ($day <= $last_day && $guard++ < 800) {
            foreach (self::read_day(self::day_path($symbol_key, $day)) as $ts => $price) {
                if ($ts >= $from_ts && $ts <= $to_ts) {
                    $samples[$ts] = $price;
                }
            }
            $day = gmdate('Y-m-d', strtotime($day . ' +1 day UTC'));
        }
        ksort($samples);
        return $samples;
    }

    /** Deletes day files older than RETENTION_DAYS. @return int files removed */
    public static function prune($now = null) {
        $now = $now === null ? time() : (int) $now;
        $cutoff = self::local_iso_date($now - (self::RETENTION_DAYS * 86400));
        $removed = 0;

        foreach (glob(self::base_dir() . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
            foreach (glob($dir . '/*.json') ?: array() as $file) {
                if (basename($file, '.json') < $cutoff && @unlink($file)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    /** Per-symbol overview for the UI: how much intraday data actually exists. */
    public static function summary() {
        $rows = array();
        foreach (glob(self::base_dir() . '/*', GLOB_ONLYDIR) ?: array() as $dir) {
            $files = glob($dir . '/*.json') ?: array();
            if (!$files) {
                continue;
            }
            sort($files);
            $samples = 0;
            foreach ($files as $file) {
                $samples += count(self::read_day($file));
            }
            $rows[] = array(
                'key' => basename($dir),
                'days' => count($files),
                'samples' => $samples,
                'first' => basename($files[0], '.json'),
                'last' => basename($files[count($files) - 1], '.json'),
            );
        }
        return $rows;
    }

    // -- aggregation (pure: no storage, no network) ---------------------------

    /**
     * Resample raw samples into the requested resolution.
     *
     * 10m/1h produce one OHLC row per non-empty bucket - empty buckets are
     * skipped rather than carried forward, because an unsampled 10 minutes is
     * genuinely "no observation", not a flat price. 'tick' keeps only the
     * samples where the price actually differs from the previous kept one,
     * which is what "every time it changed" means for sampled data.
     *
     * @param array<int,float> $samples timestamp => price (any order)
     * @param string           $resolution one of RES_10M / RES_1H / RES_TICK
     * @param int              $decimals rounding for display
     * @return array[] rows shaped like GC_Report::build_series() rows, plus ts/time
     */
    public static function aggregate($samples, $resolution, $decimals = 0) {
        if (!is_array($samples) || !$samples) {
            return array();
        }
        ksort($samples);

        if ($resolution === self::RES_TICK) {
            return self::aggregate_ticks($samples, $decimals);
        }
        if (!isset(self::BUCKET_SECONDS[$resolution])) {
            return array();
        }

        $buckets = array();
        foreach ($samples as $ts => $price) {
            $start = self::bucket_start($ts, $resolution);
            if (!isset($buckets[$start])) {
                $buckets[$start] = array();
            }
            $buckets[$start][] = (float) $price;
        }
        ksort($buckets);

        $rows = array();
        foreach ($buckets as $start => $prices) {
            $rows[] = self::make_row(
                $start,
                $prices[0],
                min($prices),
                max($prices),
                $prices[count($prices) - 1],
                // The mean of the actual observations in the window - a truer
                // "average traded price" here than the daily table's
                // (low+high+close)/3 stand-in, which exists only because the
                // daily endpoint gives no intra-day observations at all.
                array_sum($prices) / count($prices),
                count($prices),
                $decimals
            );
        }
        return $rows;
    }

    private static function aggregate_ticks($samples, $decimals) {
        $rows = array();
        $previous = null;
        foreach ($samples as $ts => $price) {
            $price = (float) $price;
            if ($previous !== null && self::same_price($price, $previous, $decimals)) {
                continue;
            }
            $row = self::make_row($ts, $price, $price, $price, $price, $price, 1, $decimals);
            $row['change'] = $previous === null
                ? null
                : self::round_value($price - $previous, $decimals);
            $rows[] = $row;
            $previous = $price;
        }
        return $rows;
    }

    /** Two samples are "the same price" when they round to the same displayed value. */
    private static function same_price($a, $b, $decimals) {
        return self::round_value($a, $decimals) == self::round_value($b, $decimals);
    }

    private static function round_value($value, $decimals) {
        if ($value === null) {
            return null;
        }
        return $decimals <= 0 ? (int) round($value) : round($value, $decimals);
    }

    private static function make_row($ts, $open, $low, $high, $close, $average, $count, $decimals) {
        $date = self::local_jalali_date($ts);
        list($jy, $jm, $jd) = array_map('intval', explode('/', $date));
        return array(
            'ts' => (int) $ts,
            'date' => $date,
            'time' => self::local_time($ts),
            'weekday' => GC_Jalali::weekday_name($jy, $jm, $jd),
            'open' => self::round_value($open, $decimals),
            'low' => self::round_value($low, $decimals),
            'high' => self::round_value($high, $decimals),
            'close' => self::round_value($close, $decimals),
            'average' => self::round_value($average, $decimals),
            'samples' => (int) $count,
            'status' => GC_Report::STATUS_LIVE,
            'live' => true,
        );
    }

    /**
     * Resample OHLC bars (extracted from TGJU) into the requested resolution.
     *
     * Distinct from aggregate(), which takes single observed prices: when the
     * source already gives bars, a bucket's high is the max of the bars' highs
     * and its low the min of their lows. Collapsing them to closes first would
     * quietly discard the extremes inside each bucket.
     *
     * @param array[] $candles each: ts, open, high, low, close
     */
    public static function aggregate_candles($candles, $resolution, $decimals = 0) {
        if (!is_array($candles) || !$candles) {
            return array();
        }
        usort($candles, function ($a, $b) {
            return (int) $a['ts'] <=> (int) $b['ts'];
        });

        if ($resolution === self::RES_TICK) {
            // "Every change" over historical bars means one row per bar whose
            // close differs from the previous kept one.
            $rows = array();
            $previous = null;
            foreach ($candles as $candle) {
                $price = $candle['close'] !== null ? $candle['close'] : $candle['open'];
                if ($price === null) {
                    continue;
                }
                $price = (float) $price;
                if ($previous !== null && self::same_price($price, $previous, $decimals)) {
                    continue;
                }
                $row = self::make_row(
                    (int) $candle['ts'], $price, $price, $price, $price, $price, 1, $decimals
                );
                $row['change'] = $previous === null
                    ? null : self::round_value($price - $previous, $decimals);
                $rows[] = $row;
                $previous = $price;
            }
            return $rows;
        }

        if (!isset(self::BUCKET_SECONDS[$resolution])) {
            return array();
        }

        $buckets = array();
        foreach ($candles as $candle) {
            $start = self::bucket_start((int) $candle['ts'], $resolution);
            if (!isset($buckets[$start])) {
                $buckets[$start] = array();
            }
            $buckets[$start][] = $candle;
        }
        ksort($buckets);

        $rows = array();
        foreach ($buckets as $start => $bars) {
            $opens = $closes = $highs = $lows = array();
            foreach ($bars as $bar) {
                if ($bar['open'] !== null) { $opens[] = (float) $bar['open']; }
                if ($bar['close'] !== null) { $closes[] = (float) $bar['close']; }
                if ($bar['high'] !== null) { $highs[] = (float) $bar['high']; }
                if ($bar['low'] !== null) { $lows[] = (float) $bar['low']; }
            }
            if (!$closes && !$opens) {
                continue;
            }
            if (!$highs) { $highs = $closes ? $closes : $opens; }
            if (!$lows) { $lows = $closes ? $closes : $opens; }

            $open = $opens ? $opens[0] : $closes[0];
            $close = $closes ? $closes[count($closes) - 1] : $opens[count($opens) - 1];
            $prices = $closes ? $closes : $opens;

            $rows[] = self::make_row(
                $start, $open, min($lows), max($highs), $close,
                array_sum($prices) / count($prices), count($bars), $decimals
            );
        }
        return $rows;
    }

    /**
     * Full intraday series for one symbol over a Jalali range, in the same
     * {rows, stats} shape GC_Report::build_series() returns so the table,
     * chart and exports can treat both the same way.
     */
    public static function build_series($symbol, $start, $end, $resolution) {
        list($from, $to) = self::range_bounds($start, $end);
        $samples = self::load_samples($symbol['key'], $from, $to);
        $rows = self::aggregate($samples, $resolution, $symbol['decimals']);
        return array('rows' => $rows, 'stats' => self::stats($rows, $symbol));
    }

    /**
     * Says *why* a symbol has no intraday rows for a range, and what to do.
     *
     * "No samples recorded" on its own is a dead end: intraday data only
     * exists from the moment recording is switched on, so the useful answer
     * is whether recording is off, or on but younger than the range asked
     * for. @return string a message ready to show the user
     */
    public static function explain_empty($symbol, $start, $end, $recording) {
        $name = $symbol['name'];
        $stored = self::symbol_summary($symbol['key']);

        if (!$stored) {
            return $recording
                ? 'ثبت خودکار روشن است اما هنوز هیچ نمونه‌ای برای «' . $name . '» ذخیره نشده'
                    . '؛ چند دقیقه صبر کنید یا «ثبت نمونه همین حالا» را بزنید.'
                : 'برای «' . $name . '» هنوز داده درون‌روزی وجود ندارد. کلید «ثبت خودکار قیمت هر ۱۰ دقیقه»'
                    . ' را روشن کنید؛ از همان لحظه ثبت شروع می‌شود (داده گذشته قابل بازیابی نیست).';
        }

        // Data exists, just not inside the window they asked for.
        $first = self::jalali_of_iso($stored['first']);
        $last = self::jalali_of_iso($stored['last']);
        return 'ثبت درون‌روزی «' . $name . '» از ' . $first . ' شروع شده و تا ' . $last
            . ' داده دارد؛ بازه‌ای که انتخاب کرده‌اید بیرون از این محدوده است.'
            . ' بازه را به «امروز» تغییر دهید.';
    }

    /** @return array|null the summary() row for one symbol, if it has data */
    public static function symbol_summary($symbol_key) {
        $dir = self::symbol_dir($symbol_key);
        $files = glob($dir . '/*.json') ?: array();
        if (!$files) {
            return null;
        }
        sort($files);
        return array(
            'first' => basename($files[0], '.json'),
            'last' => basename($files[count($files) - 1], '.json'),
        );
    }

    /** '2025-08-19' -> '۱۴۰۴/۰۵/۲۸', to read like the rest of the Persian UI. */
    private static function jalali_of_iso($iso) {
        $parts = array_map('intval', explode('-', $iso));
        if (count($parts) !== 3) {
            return $iso;
        }
        list($jy, $jm, $jd) = GC_Jalali::gregorian_to_jalali($parts[0], $parts[1], $parts[2]);
        return str_replace(
            array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9'),
            array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'),
            GC_Jalali::format($jy, $jm, $jd)
        );
    }

    public static function stats($rows, $symbol) {
        $closes = array();
        foreach ($rows as $row) {
            if ($row['close'] !== null) {
                $closes[] = $row['close'];
            }
        }
        $first = $closes ? $closes[0] : null;
        $last = $closes ? $closes[count($closes) - 1] : null;

        $change = null;
        $change_pct = null;
        if ($first !== null && $last !== null && $first != 0) {
            $change = self::round_value($last - $first, $symbol['decimals']);
            $change_pct = round((($last - $first) / $first) * 100, 2);
        }

        return array(
            'days' => count($rows),
            'trading_days' => count($closes),
            'first' => $first,
            'last' => $last,
            'min' => $closes ? min($closes) : null,
            'max' => $closes ? max($closes) : null,
            'mean' => $closes ? self::round_value(array_sum($closes) / count($closes), $symbol['decimals']) : null,
            'change' => $change,
            'change_pct' => $change_pct,
            'unit' => GC_Symbols::unit_label($symbol['currency']),
        );
    }
}
