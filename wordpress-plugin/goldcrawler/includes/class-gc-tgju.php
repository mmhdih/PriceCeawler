<?php
/**
 * TGJU (tgju.org) historical price API: fetch + parse.
 *
 * Ported from priceceawler/tgju.py. Parsing has no WordPress dependency
 * (pure string/array logic, testable standalone); only fetch() uses
 * wp_remote_get so requests go through WordPress's own HTTP layer
 * (proxy/SSL config, user-agent policy, etc. all handled consistently).
 */

if (!defined('ABSPATH') && !defined('GC_STANDALONE_TEST')) {
    exit;
}

class GC_Tgju_Exception extends Exception {}

final class GC_Tgju {

    const API_URL = 'https://api.tgju.org/v1/market/indicator/summary-table-data/%s?lang=fa&order_dir=asc&start=0&length=%d';

    private static function clean($value) {
        $text = preg_replace('/<[^>]+>/', '', (string) $value);
        $text = GC_Jalali::to_english_digits($text);
        $text = str_replace(array(',', '%'), '', $text);
        $text = str_replace("\xD9\xAC", '', $text); // ٬ ARABIC THOUSANDS SEPARATOR (U+066C)
        return trim($text);
    }

    private static function as_number($value, $divisor) {
        $text = self::clean($value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return null;
        }
        $number = (float) $text;
        if ($number === 0.0) {
            return null;
        }
        return $number / $divisor;
    }

    private static function normalise_jalali($value) {
        $text = GC_Jalali::to_english_digits(preg_replace('/<[^>]+>/', '', (string) $value));
        $text = trim(str_replace('-', '/', $text));
        if (!preg_match('/^1[2-5]\d{2}\/\d{1,2}\/\d{1,2}$/', $text)) {
            return null;
        }
        $parts = array_map('intval', explode('/', $text));
        $parsed = GC_Jalali::parse(GC_Jalali::format($parts[0], $parts[1], $parts[2]));
        if ($parsed === null) {
            return null;
        }
        return GC_Jalali::format($parsed[0], $parsed[1], $parsed[2]);
    }

    private static function normalise_gregorian($value) {
        $text = GC_Jalali::to_english_digits(preg_replace('/<[^>]+>/', '', (string) $value));
        $text = trim(str_replace('/', '-', $text));
        if (!preg_match('/^(19|20)\d{2}-\d{1,2}-\d{1,2}$/', $text)) {
            return '';
        }
        $parts = array_map('intval', explode('-', $text));
        return sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]);
    }

    private static function row_values($row) {
        if (is_array($row)) {
            if (self::is_assoc($row)) {
                $preferred = array('open', 'low', 'high', 'close', 'change', 'percent', 'date_gregorian', 'date');
                $has_preferred = false;
                foreach ($preferred as $key) {
                    if (array_key_exists($key, $row)) {
                        $has_preferred = true;
                        break;
                    }
                }
                if ($has_preferred) {
                    $values = array();
                    foreach ($preferred as $key) {
                        $values[] = isset($row[$key]) ? $row[$key] : null;
                    }
                    return $values;
                }
                return array_values($row);
            }
            return array_values($row);
        }
        throw new GC_Tgju_Exception('قالب داده دریافتی از TGJU شناخته شده نیست.');
    }

    private static function is_assoc($array) {
        if ($array === array()) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * @param array $payload decoded JSON payload (assoc array)
     * @param array $symbol  from GC_Symbols::get()/custom()
     * @return array<string, array{date:string, gregorian:string, open:?float, low:?float, high:?float, close:?float, average:?float}>
     *         keyed by Jalali date, sorted ascending.
     */
    public static function parse_rows($payload, $symbol) {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $rows = $payload['data'];
        } elseif (isset($payload['rows']) && is_array($payload['rows'])) {
            $rows = $payload['rows'];
        } elseif (self::is_list($payload)) {
            $rows = $payload;
        } else {
            throw new GC_Tgju_Exception('پاسخ دریافتی از TGJU قابل خواندن نیست.');
        }

        $divisor = GC_Symbols::divisor($symbol['currency']);
        $by_date = array();

        foreach ($rows as $row) {
            try {
                $cells = self::row_values($row);
            } catch (GC_Tgju_Exception $e) {
                continue;
            }

            $jalali = null;
            for ($i = count($cells) - 1; $i >= 0; $i--) {
                $candidate = self::normalise_jalali($cells[$i]);
                if ($candidate !== null) {
                    $jalali = $candidate;
                    break;
                }
            }
            if ($jalali === null) {
                continue;
            }

            $gregorian = '';
            for ($i = count($cells) - 1; $i >= 0; $i--) {
                $candidate = self::normalise_gregorian($cells[$i]);
                if ($candidate !== '') {
                    $gregorian = $candidate;
                    break;
                }
            }

            $numbers = array();
            for ($i = 0; $i < 4; $i++) {
                $numbers[] = self::as_number(isset($cells[$i]) ? $cells[$i] : null, $divisor);
            }
            list($open, $low, $high, $close) = $numbers;

            if ($low !== null && $high !== null && $low > $high) {
                $tmp = $low; $low = $high; $high = $tmp;
            }
            if ($close === null && $high !== null && $low !== null) {
                $close = ($high + $low) / 2;
            }
            if ($low === null && $close !== null) {
                $low = $close;
            }
            if ($high === null && $close !== null) {
                $high = $close;
            }
            if ($close === null) {
                continue;
            }

            $average = null;
            $values = array_filter(array($low, $high, $close), function ($v) { return $v !== null; });
            if ($values) {
                $average = array_sum($values) / count($values);
            }

            $by_date[$jalali] = array(
                'date' => $jalali, 'gregorian' => $gregorian,
                'open' => $open, 'low' => $low, 'high' => $high, 'close' => $close, 'average' => $average,
            );
        }

        if (!$by_date) {
            throw new GC_Tgju_Exception(sprintf('هیچ رکورد معتبری برای نماد «%s» در پاسخ TGJU پیدا نشد.', $symbol['key']));
        }
        ksort($by_date);
        return $by_date;
    }

    private static function is_list($value) {
        if (!is_array($value)) {
            return false;
        }
        return $value === array() || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Fetch full history for one symbol. Requires WordPress (wp_remote_get).
     *
     * @throws GC_Tgju_Exception with a Persian message on failure.
     */
    public static function fetch($symbol, $length = 5000, $retries = 3, $timeout = 30) {
        $url = sprintf(self::API_URL, rawurlencode($symbol['key']), (int) $length);
        $last_error = null;

        for ($attempt = 0; $attempt < max(1, $retries); $attempt++) {
            $response = wp_remote_get($url, array(
                'timeout' => $timeout,
                'headers' => array(
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Accept' => 'application/json, text/javascript, */*; q=0.01',
                    'Accept-Language' => 'fa,en;q=0.8',
                    'Referer' => 'https://www.tgju.org/profile/' . rawurlencode($symbol['key']) . '/history',
                ),
                'user-agent' => 'Mozilla/5.0 (compatible; GoldCrawlerWP/1.0; +' . home_url() . ')',
            ));

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 404) {
                    throw new GC_Tgju_Exception(sprintf('نماد «%s» در TGJU وجود ندارد (خطای ۴۰۴).', $symbol['key']));
                }
                if ($code >= 400 && $code < 500 && $code !== 429) {
                    throw new GC_Tgju_Exception(sprintf('دریافت «%s» با خطای %d از TGJU روبه‌رو شد.', $symbol['name'], $code));
                }
                if ($code >= 200 && $code < 300) {
                    $body = wp_remote_retrieve_body($response);
                    $payload = json_decode($body, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
                        throw new GC_Tgju_Exception('پاسخ TGJU یک JSON معتبر نبود (احتمالاً صفحه خطا).');
                    }
                    return self::parse_rows($payload, $symbol);
                }
                $last_error = "HTTP {$code}";
            }

            if ($attempt < $retries - 1) {
                usleep((int) (1.5 * pow(2, $attempt) * 1000000));
            }
        }

        throw new GC_Tgju_Exception(sprintf(
            'اتصال به TGJU برای «%s» برقرار نشد. اتصال اینترنت سرور را بررسی کنید. (%s)',
            $symbol['name'], $last_error
        ));
    }
}
