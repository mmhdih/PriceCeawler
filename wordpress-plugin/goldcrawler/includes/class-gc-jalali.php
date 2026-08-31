<?php
/**
 * Jalali (Shamsi) calendar conversion — pure PHP, no WordPress dependency.
 *
 * Ported from priceceawler/jalali.py; same algorithm (the standard
 * jdatetime/JalaliJSCalendar break-point table), so it stays exact for
 * Jalali years 1..3177. See tests/test-jalali.php, which cross-checks this
 * implementation against the already-verified Python one.
 */

if (!defined('ABSPATH')) {
    // Allow the CLI test harness to load this file standalone.
    if (!defined('GC_STANDALONE_TEST')) {
        exit;
    }
}

final class GC_Jalali {

    const MONTH_NAMES = array(
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    );

    // Index 0 = Saturday, matching self::weekday().
    const WEEKDAY_NAMES = array(
        'شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه',
    );

    const J_DAYS_IN_MONTH = array(31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29);

    private static $persian_digits = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    private static $arabic_digits = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');

    public static function to_english_digits($value) {
        $value = (string) $value;
        $value = str_replace(self::$persian_digits, range(0, 9), $value);
        $value = str_replace(self::$arabic_digits, range(0, 9), $value);
        return $value;
    }

    /** @return array{0:int,1:int,2:int} [leap(0=leap), gregorian_year, march_day] */
    private static function leap_offset($jy) {
        $breaks = array(
            -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060,
            2097, 2192, 2262, 2324, 2394, 2456, 3178,
        );
        $gy = $jy + 621;
        $leap_j = -14;
        $jp = $breaks[0];
        $count = count($breaks);

        if ($jy < $jp || $jy >= $breaks[$count - 1]) {
            throw new InvalidArgumentException("سال شمسی خارج از محدوده پشتیبانی است: {$jy}");
        }

        $jump = 0;
        for ($i = 1; $i < $count; $i++) {
            $jm = $breaks[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) {
                break;
            }
            $leap_j += intdiv($jump, 33) * 8 + intdiv($jump % 33, 4);
            $jp = $jm;
        }

        $n = $jy - $jp;
        $leap_j += intdiv($n, 33) * 8 + intdiv(($n % 33) + 3, 4);
        if ($jump % 33 === 4 && ($jump - $n) === 4) {
            $leap_j += 1;
        }

        // Must multiply by 3 BEFORE the integer division (matches Python's
        // `(gy // 100 + 1) * 3 // 4`) - dividing first and then multiplying
        // gives a different, wrong truncation for roughly half of all years.
        $leap_g = intdiv($gy, 4) - intdiv((intdiv($gy, 100) + 1) * 3, 4) - 150;
        $march = 20 + $leap_j - $leap_g;

        if (($jump - $n) < 6) {
            $n = $n - $jump + intdiv($jump + 4, 33) * 33;
        }
        $leap = (($n + 1) % 33 - 1) % 4;
        if ($leap === -1) {
            $leap = 4;
        }
        return array($leap, $gy, $march);
    }

    /** @return array{0:int,1:int,2:int} [year, month, day] */
    public static function jalali_to_gregorian($jy, $jm, $jd) {
        list(, $gy, $march) = self::leap_offset($jy);
        $ordinal = self::gregorian_to_ordinal($gy, 3, $march);
        if ($jm < 7) {
            $ordinal += ($jm - 1) * 31 + $jd - 1;
        } else {
            $ordinal += 6 * 31 + ($jm - 7) * 30 + $jd - 1;
        }
        return self::ordinal_to_gregorian($ordinal);
    }

    /** @return array{0:int,1:int,2:int} [year, month, day] */
    public static function gregorian_to_jalali($gy, $gm, $gd) {
        $ordinal = self::gregorian_to_ordinal($gy, $gm, $gd);
        $jy = $gy - 621;
        list(, , $march) = self::leap_offset($jy);
        $start = self::gregorian_to_ordinal($gy, 3, $march);
        if ($ordinal < $start) {
            $jy -= 1;
            list(, $ny, $march2) = self::leap_offset($jy);
            $start = self::gregorian_to_ordinal($ny, 3, $march2);
        }

        $days = $ordinal - $start;
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + $days % 31;
        } else {
            $days -= 186;
            $jm = 7 + intdiv($days, 30);
            $jd = 1 + $days % 30;
        }
        return array($jy, $jm, $jd);
    }

    // -- proleptic Gregorian ordinal (day 1 = 0001-01-01), matching Python's
    //    datetime.date.toordinal()/fromordinal() exactly, so both languages
    //    agree bit-for-bit on every intermediate value. ----------------------

    private static function is_gregorian_leap($year) {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    private static function days_before_year($year) {
        $y = $year - 1;
        return $y * 365 + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400);
    }

    private static function days_before_month($year, $month) {
        static $cum = array(0, 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
        $days = $cum[$month];
        if ($month > 2 && self::is_gregorian_leap($year)) {
            $days += 1;
        }
        return $days;
    }

    public static function gregorian_to_ordinal($year, $month, $day) {
        return self::days_before_year($year) + self::days_before_month($year, $month) + $day;
    }

    public static function ordinal_to_gregorian($ordinal) {
        // Binary-search the year via the same day-count formulas used above.
        $year = (int) floor(($ordinal - 1) / 365.2425) + 1;
        while (self::days_before_year($year + 1) < $ordinal) {
            $year++;
        }
        while (self::days_before_year($year) >= $ordinal) {
            $year--;
        }
        $day_of_year = $ordinal - self::days_before_year($year);

        $month = 1;
        while (true) {
            $days_in_month = self::gregorian_days_in_month($year, $month);
            if ($day_of_year <= $days_in_month) {
                break;
            }
            $day_of_year -= $days_in_month;
            $month++;
        }
        return array($year, $month, $day_of_year);
    }

    private static function gregorian_days_in_month($year, $month) {
        static $days = array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
        if ($month === 2 && self::is_gregorian_leap($year)) {
            return 29;
        }
        return $days[$month - 1];
    }

    // -- public helpers -------------------------------------------------

    public static function is_leap_year($jy) {
        return self::leap_offset($jy)[0] === 0;
    }

    public static function days_in_month($jy, $jm) {
        if ($jm < 1 || $jm > 12) {
            throw new InvalidArgumentException("ماه نامعتبر است: {$jm}");
        }
        if ($jm === 12 && self::is_leap_year($jy)) {
            return 30;
        }
        return self::J_DAYS_IN_MONTH[$jm - 1];
    }

    public static function today() {
        // WordPress runs on server-local time; the crawler cares about
        // calendar days, not exact instants, so PHP's local date() is fine.
        list($gy, $gm, $gd) = array((int) date('Y'), (int) date('n'), (int) date('j'));
        return self::gregorian_to_jalali($gy, $gm, $gd);
    }

    public static function format($jy, $jm, $jd) {
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /** @return array{0:int,1:int,2:int}|null */
    public static function parse($text) {
        $text = self::to_english_digits((string) $text);
        $text = trim($text);
        $text = preg_replace('/[\-.]/', '/', $text);
        if (!preg_match('/^(\d{3,4})\/(\d{1,2})\/(\d{1,2})$/', $text, $m)) {
            return null;
        }
        $jy = (int) $m[1];
        $jm = (int) $m[2];
        $jd = (int) $m[3];
        if ($jm < 1 || $jm > 12) {
            return null;
        }
        try {
            $limit = self::days_in_month($jy, $jm);
        } catch (InvalidArgumentException $e) {
            return null;
        }
        if ($jd < 1 || $jd > $limit) {
            return null;
        }
        return array($jy, $jm, $jd);
    }

    public static function weekday_index($jy, $jm, $jd) {
        list($gy, $gm, $gd) = self::jalali_to_gregorian($jy, $jm, $jd);
        $ordinal = self::gregorian_to_ordinal($gy, $gm, $gd);
        // PHP has no direct weekday-from-ordinal; ordinal 1 = 0001-01-01 = Monday.
        // (0=Mon..6=Sun in ISO terms) -> convert to 0=Saturday..6=Friday.
        $iso_weekday = (($ordinal - 1) % 7 + 7) % 7; // 0=Mon .. 6=Sun
        return ($iso_weekday + 2) % 7; // 0=Sat .. 6=Fri
    }

    public static function weekday_name($jy, $jm, $jd) {
        return self::WEEKDAY_NAMES[self::weekday_index($jy, $jm, $jd)];
    }

    public static function month_name($jm) {
        return self::MONTH_NAMES[$jm - 1];
    }

    public static function add_days($jy, $jm, $jd, $days) {
        list($gy, $gm, $gd) = self::jalali_to_gregorian($jy, $jm, $jd);
        $ordinal = self::gregorian_to_ordinal($gy, $gm, $gd) + $days;
        list($ngy, $ngm, $ngd) = self::ordinal_to_gregorian($ordinal);
        return self::gregorian_to_jalali($ngy, $ngm, $ngd);
    }

    /** Day-count between two Jalali dates (b - a), like Python's JalaliDate.__sub__. */
    public static function diff_days($ay, $am, $ad, $by, $bm, $bd) {
        list($agy, $agm, $agd) = self::jalali_to_gregorian($ay, $am, $ad);
        list($bgy, $bgm, $bgd) = self::jalali_to_gregorian($by, $bm, $bd);
        return self::gregorian_to_ordinal($bgy, $bgm, $bgd) - self::gregorian_to_ordinal($agy, $agm, $agd);
    }

    /** @return string[] every "YYYY/MM/DD" from $start to $end inclusive. */
    public static function date_range($start_y, $start_m, $start_d, $end_y, $end_m, $end_d) {
        $dates = array();
        list($gy, $gm, $gd) = self::jalali_to_gregorian($start_y, $start_m, $start_d);
        $ordinal = self::gregorian_to_ordinal($gy, $gm, $gd);
        list($egy, $egm, $egd) = self::jalali_to_gregorian($end_y, $end_m, $end_d);
        $end_ordinal = self::gregorian_to_ordinal($egy, $egm, $egd);

        for ($o = $ordinal; $o <= $end_ordinal; $o++) {
            list($cgy, $cgm, $cgd) = self::ordinal_to_gregorian($o);
            list($cjy, $cjm, $cjd) = self::gregorian_to_jalali($cgy, $cgm, $cgd);
            $dates[] = self::format($cjy, $cjm, $cjd);
        }
        return $dates;
    }
}
