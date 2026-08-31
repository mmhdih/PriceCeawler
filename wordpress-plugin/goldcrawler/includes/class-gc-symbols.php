<?php
/**
 * Catalogue of TGJU indicators the plugin can crawl.
 *
 * Mirrors priceceawler/symbols.py. No WordPress dependency.
 */

if (!defined('ABSPATH') && !defined('GC_STANDALONE_TEST')) {
    exit;
}

final class GC_Symbols {

    const CURRENCY_IRR = 'IRR'; // ریال روی TGJU -> نمایش به تومان
    const CURRENCY_USD = 'USD';

    /** @var array<string, array{name:string, group:string, currency:string, decimals:int}>|null */
    private static $catalog = null;

    private static function build_catalog() {
        $defs = array(
            // طلا و نقره
            array('geram18', 'طلای ۱۸ عیار', 'طلا و نقره', self::CURRENCY_IRR, 0),
            array('geram24', 'طلای ۲۴ عیار', 'طلا و نقره', self::CURRENCY_IRR, 0),
            array('mesghal', 'مثقال طلا', 'طلا و نقره', self::CURRENCY_IRR, 0),
            array('gold_17', 'طلای ۱۷ عیار', 'طلا و نقره', self::CURRENCY_IRR, 0),
            array('ons', 'انس جهانی طلا', 'طلا و نقره', self::CURRENCY_USD, 2),
            array('silver', 'انس جهانی نقره', 'طلا و نقره', self::CURRENCY_USD, 2),
            array('silver_925', 'نقره ۹۲۵', 'طلا و نقره', self::CURRENCY_IRR, 0),
            array('platinum', 'انس پلاتین', 'طلا و نقره', self::CURRENCY_USD, 2),
            // سکه
            array('sekee', 'سکه امامی', 'سکه', self::CURRENCY_IRR, 0),
            array('sekeb', 'سکه بهار آزادی', 'سکه', self::CURRENCY_IRR, 0),
            array('nim', 'نیم سکه', 'سکه', self::CURRENCY_IRR, 0),
            array('rob', 'ربع سکه', 'سکه', self::CURRENCY_IRR, 0),
            array('gerami', 'سکه گرمی', 'سکه', self::CURRENCY_IRR, 0),
            array('retail_sekee', 'سکه امامی (خرده‌فروشی)', 'سکه', self::CURRENCY_IRR, 0),
            // ارز
            array('price_dollar_rl', 'دلار آمریکا', 'ارز', self::CURRENCY_IRR, 0),
            array('price_eur', 'یورو', 'ارز', self::CURRENCY_IRR, 0),
            array('price_gbp', 'پوند انگلیس', 'ارز', self::CURRENCY_IRR, 0),
            array('price_aed', 'درهم امارات', 'ارز', self::CURRENCY_IRR, 0),
            array('price_try', 'لیر ترکیه', 'ارز', self::CURRENCY_IRR, 0),
            array('price_cad', 'دلار کانادا', 'ارز', self::CURRENCY_IRR, 0),
            array('price_aud', 'دلار استرالیا', 'ارز', self::CURRENCY_IRR, 0),
            array('price_chf', 'فرانک سوئیس', 'ارز', self::CURRENCY_IRR, 0),
            array('price_cny', 'یوان چین', 'ارز', self::CURRENCY_IRR, 0),
            array('price_rub', 'روبل روسیه', 'ارز', self::CURRENCY_IRR, 0),
            array('price_jpy', 'ین ژاپن', 'ارز', self::CURRENCY_IRR, 0),
            array('price_iqd', 'دینار عراق', 'ارز', self::CURRENCY_IRR, 0),
            // رمزارز
            array('crypto-bitcoin', 'بیت‌کوین', 'رمزارز', self::CURRENCY_USD, 2),
            array('crypto-ethereum', 'اتریوم', 'رمزارز', self::CURRENCY_USD, 2),
            array('crypto-tether', 'تتر', 'رمزارز', self::CURRENCY_USD, 4),
            // نفت و کالا
            array('oil_brent', 'نفت برنت', 'نفت و کالا', self::CURRENCY_USD, 2),
            array('oil_wti', 'نفت وست تگزاس', 'نفت و کالا', self::CURRENCY_USD, 2),
            array('copper', 'مس', 'نفت و کالا', self::CURRENCY_USD, 4),
        );

        $catalog = array();
        foreach ($defs as $d) {
            $catalog[$d[0]] = array(
                'key' => $d[0], 'name' => $d[1], 'group' => $d[2],
                'currency' => $d[3], 'decimals' => $d[4],
            );
        }
        return $catalog;
    }

    public static function catalog() {
        if (self::$catalog === null) {
            self::$catalog = self::build_catalog();
        }
        return self::$catalog;
    }

    public static function default_selection() {
        return array('geram18', 'sekee', 'price_dollar_rl');
    }

    public static function unit_label($currency) {
        return $currency === self::CURRENCY_USD ? 'دلار' : 'تومان';
    }

    public static function divisor($currency) {
        return $currency === self::CURRENCY_IRR ? 10.0 : 1.0;
    }

    public static function get($key) {
        $catalog = self::catalog();
        return isset($catalog[$key]) ? $catalog[$key] : null;
    }

    public static function custom($key, $name = null, $currency = self::CURRENCY_IRR, $group = null, $decimals = null) {
        $key = trim($key);
        return array(
            'key' => $key,
            'name' => $name ? trim($name) : $key,
            'group' => $group ? trim($group) : 'نمادهای دلخواه',
            'currency' => $currency === self::CURRENCY_USD ? self::CURRENCY_USD : self::CURRENCY_IRR,
            'decimals' => is_numeric($decimals) ? max(0, min(8, (int) $decimals)) : 0,
            'custom' => true,
        );
    }

    public static function to_dict($symbol) {
        return array(
            'key' => $symbol['key'],
            'name' => $symbol['name'],
            'group' => $symbol['group'],
            'currency' => $symbol['currency'],
            'decimals' => $symbol['decimals'],
            'unit' => self::unit_label($symbol['currency']),
            'custom' => !empty($symbol['custom']),
        );
    }

    public static function is_valid_custom_key($key) {
        return (bool) preg_match('/^[A-Za-z0-9_.-]{2,64}$/', $key);
    }
}
