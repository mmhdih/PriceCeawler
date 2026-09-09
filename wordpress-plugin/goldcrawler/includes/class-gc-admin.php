<?php
/**
 * Admin page: Settings → GoldCrawler — grant/revoke access per registered
 * user. Separate from GC_License (which only stores/checks data); this
 * class is purely the admin-facing form around it.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GC_Admin {

    const PAGE_SLUG = 'goldcrawler-access';
    const NONCE_ACTION = 'goldcrawler_save_access';
    const NONCE_ACTION_SYMBOLS = 'goldcrawler_save_symbols';
    const NONCE_ACTION_INTRADAY = 'goldcrawler_save_intraday';

    /** Probe results for this request, rendered under the form. */
    private static $probe_report = array();

    public static function register() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
    }

    public static function add_menu() {
        // A top-level entry in the admin sidebar, not a page buried under
        // Settings: this plugin has its own symbols, access list and
        // intraday recording to manage, which is more than a settings pane.
        add_menu_page(
            'GoldCrawler — قیمت طلا و ارز',
            'GoldCrawler',
            GC_License::MANAGE_CAPABILITY,
            self::PAGE_SLUG,
            array(__CLASS__, 'render_page'),
            self::menu_icon(),
            58
        );

        // Deliberately the *only* registration for this slug. Registering the
        // same slug under a second parent (e.g. also under Settings, to keep
        // an old bookmark alive) makes WordPress resolve the parent file
        // ambiguously and highlight the wrong menu.
    }

    /**
     * A data: URI SVG so the menu icon needs no HTTP request and inherits
     * the admin colour scheme (currentColor is what WordPress recolours).
     */
    private static function menu_icon() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round"><path d="M3 3v16a2 2 0 0 0 2 2h16"/>'
            . '<path d="M7 15l3.5-4.5 3 3L20 6"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function handle_save() {
        if (!isset($_POST['goldcrawler_nonce']) || !wp_verify_nonce($_POST['goldcrawler_nonce'], self::NONCE_ACTION)) {
            return array('type' => 'error', 'text' => 'نشست فرم منقضی شده؛ دوباره تلاش کنید.');
        }
        if (!current_user_can(GC_License::MANAGE_CAPABILITY)) {
            return array('type' => 'error', 'text' => 'شما اجازه تغییر این تنظیمات را ندارید.');
        }

        $allow_all = !empty($_POST['goldcrawler_allow_all']);
        GC_License::set_allow_all($allow_all);

        // While allow_all is on, the per-user checkboxes are disabled in the
        // form and so never get submitted at all - saving that empty
        // selection would silently wipe the individual allowlist. Leave it
        // untouched so it's still there if the admin turns allow_all back off.
        if ($allow_all) {
            return array('type' => 'success', 'text' => 'دسترسی برای همه کاربران واردشده فعال شد.');
        }

        $selected = isset($_POST['goldcrawler_users']) ? (array) $_POST['goldcrawler_users'] : array();
        $ids = GC_License::set_licensed_user_ids($selected);
        $count = count($ids);
        return array('type' => 'success', 'text' => "دسترسی برای {$count} کاربر ذخیره شد."); // Persian digits are cosmetic; not required for an admin screen
    }

    /**
     * Saves all three symbol-management actions from one form submit:
     * which built-in TGJU symbols are enabled, which admin-added ("custom")
     * symbols got deleted, and (optionally) one new custom symbol to add.
     * Unchecked checkboxes never submit at all in HTML forms, so "enabled"
     * is derived from what *did* arrive, not from an explicit "disabled" flag.
     */
    private static function handle_save_symbols() {
        if (!isset($_POST['goldcrawler_symbols_nonce']) || !wp_verify_nonce($_POST['goldcrawler_symbols_nonce'], self::NONCE_ACTION_SYMBOLS)) {
            return array('type' => 'error', 'text' => 'نشست فرم منقضی شده؛ دوباره تلاش کنید.');
        }
        if (!current_user_can(GC_License::MANAGE_CAPABILITY)) {
            return array('type' => 'error', 'text' => 'شما اجازه تغییر این تنظیمات را ندارید.');
        }

        $enabled = isset($_POST['goldcrawler_enabled']) ? array_flip((array) $_POST['goldcrawler_enabled']) : array();
        $disabled = array();
        foreach (GC_Symbols::catalog() as $key => $def) {
            if (!isset($enabled[$key])) {
                $disabled[] = $key;
            }
        }

        $settings = GC_Storage::get_settings();
        $to_delete = isset($_POST['goldcrawler_delete_custom']) ? array_flip((array) $_POST['goldcrawler_delete_custom']) : array();
        $customs = array_values(array_filter((array) $settings['custom_symbols'], function ($c) use ($to_delete) {
            return !isset($to_delete[$c['key'] ?? '']);
        }));

        $added_message = '';
        $new_key = trim((string) ($_POST['goldcrawler_new_key'] ?? ''));
        if ($new_key !== '') {
            if (!GC_Symbols::is_valid_custom_key($new_key)) {
                return array('type' => 'error', 'text' => 'شناسه نماد تازه فقط می‌تواند شامل حروف انگلیسی، عدد، «-»، «_» و «.» باشد (۲ تا ۶۴ کاراکتر).');
            }
            $new_name = trim((string) ($_POST['goldcrawler_new_name'] ?? '')) ?: $new_key;
            $new_group = trim((string) ($_POST['goldcrawler_new_group'] ?? '')) ?: 'نمادهای دلخواه';
            $new_currency = strtoupper((string) ($_POST['goldcrawler_new_currency'] ?? 'IRR')) === 'USD' ? 'USD' : 'IRR';
            $new_decimals = max(0, min(8, (int) ($_POST['goldcrawler_new_decimals'] ?? 0)));

            $customs = array_values(array_filter($customs, function ($c) use ($new_key) {
                return ($c['key'] ?? '') !== $new_key;
            }));
            $customs[] = array(
                'key' => $new_key, 'name' => $new_name, 'group' => $new_group,
                'currency' => $new_currency, 'decimals' => $new_decimals,
            );
            $added_message = " و نماد «{$new_name}» اضافه شد";
        }

        GC_Storage::update_settings(array(
            'disabled_symbols' => $disabled,
            'custom_symbols' => array_slice($customs, -100),
        ));

        return array('type' => 'success', 'text' => 'فهرست نمادها ذخیره شد' . $added_message . '.');
    }

    public static function render_page() {
        if (!current_user_can(GC_License::MANAGE_CAPABILITY)) {
            wp_die('شما اجازه دسترسی به این صفحه را ندارید.');
        }

        $notice = null;
        $symbols_notice = null;
        $intraday_notice = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['goldcrawler_save'])) {
                $notice = self::handle_save();
            } elseif (isset($_POST['goldcrawler_save_symbols'])) {
                $symbols_notice = self::handle_save_symbols();
            } elseif (isset($_POST['goldcrawler_save_intraday'])) {
                $intraday_notice = self::handle_save_intraday();
            } elseif (isset($_POST['goldcrawler_probe_intraday'])) {
                $intraday_notice = self::handle_probe_intraday();
            }
        }

        $licensed = GC_License::licensed_user_ids();
        $allow_all = GC_License::allow_all_enabled();
        $users = get_users(array('orderby' => 'display_name', 'order' => 'ASC'));

        echo '<div class="wrap"><h1>دسترسی GoldCrawler</h1>';
        echo '<p>مدیران سایت همیشه دسترسی دارند. برای بقیه کاربران، یا دسترسی را برای'
            . ' «همه کاربران واردشده به سایت» به‌صورت پیش‌فرض باز بگذارید، یا از فهرست زیر'
            . ' تک‌تک مشخص کنید چه کسی به ابزار <code>[gold_crawler]</code> دسترسی داشته باشد.</p>';

        if ($notice) {
            $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notice['text']) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, 'goldcrawler_nonce');

        echo '<h2 class="title">دسترسی پیش‌فرض</h2>';
        echo '<label style="display:flex;align-items:center;gap:8px;font-size:14px;">'
            . '<input type="checkbox" id="goldcrawler-allow-all" name="goldcrawler_allow_all" value="1"'
            . ($allow_all ? ' checked' : '') . ' onchange="goldcrawlerToggleAllowAll(this.checked)">'
            . 'به‌صورت پیش‌فرض برای همه کاربرانِ واردشده به سایت فعال باشد</label>';
        echo '<p class="description">اگر این گزینه را فعال کنید، هر کاربری که به سایت وارد شده باشد'
            . ' (با هر نقشی) بدون نیاز به لایسنس جداگانه به ابزار دسترسی خواهد داشت و فهرست زیر نادیده گرفته می‌شود.'
            . ' اگر خاموش باشد، فقط کاربرانی که در فهرست زیر تیک خورده‌اند (به‌علاوه مدیران) دسترسی دارند.</p>';

        echo '<h2 class="title">دسترسی تک‌به‌تک کاربران</h2>';
        echo '<p><input type="search" id="goldcrawler-user-search" placeholder="جست‌وجوی کاربر..." '
            . 'style="max-width:320px" onkeyup="goldcrawlerFilterUsers(this.value)"></p>';
        echo '<table class="widefat striped" id="goldcrawler-user-table"' . ($allow_all ? ' style="opacity:.5"' : '') . '><thead><tr>'
            . '<th style="width:40px"></th><th>نام نمایشی</th><th>نام کاربری</th><th>ایمیل</th><th>نقش</th>'
            . '</tr></thead><tbody>';

        foreach ($users as $user) {
            $is_admin = in_array('administrator', (array) $user->roles, true);
            $checked = $is_admin || in_array($user->ID, $licensed, true);
            $disabled = $is_admin || $allow_all; // admins are implicitly always allowed; the whole list is moot while allow_all is on
            echo '<tr data-name="' . esc_attr(strtolower($user->display_name . ' ' . $user->user_login)) . '">';
            echo '<td><input type="checkbox" class="goldcrawler-user-checkbox" name="goldcrawler_users[]" value="' . esc_attr($user->ID) . '"'
                . ($checked ? ' checked' : '') . ($disabled ? ' disabled' : '')
                . ($is_admin ? ' title="مدیران همیشه دسترسی دارند"' : '') . '></td>';
            echo '<td>' . esc_html($user->display_name) . '</td>';
            echo '<td>' . esc_html($user->user_login) . '</td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . esc_html(implode('، ', (array) $user->roles)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" name="goldcrawler_save" class="button button-primary">ذخیره</button></p>';
        echo '</form>';
        echo '<script>'
            . 'function goldcrawlerFilterUsers(q){q=q.toLowerCase();document.querySelectorAll("#goldcrawler-user-table tbody tr").forEach(function(r){r.style.display=r.dataset.name.indexOf(q)>-1?"":"none";});}'
            . 'function goldcrawlerToggleAllowAll(on){'
            . 'document.getElementById("goldcrawler-user-table").style.opacity=on?".5":"1";'
            . 'document.querySelectorAll(".goldcrawler-user-checkbox").forEach(function(c){if(!c.title){c.disabled=on;}});'
            . '}'
            . '</script>';

        self::render_symbols_section($symbols_notice);
        self::render_intraday_section($intraday_notice);

        echo '</div>';
    }

    /** Saves the intraday data source and (optional) chart endpoint. */
    private static function handle_save_intraday() {
        if (!isset($_POST['goldcrawler_intraday_nonce'])
            || !wp_verify_nonce($_POST['goldcrawler_intraday_nonce'], self::NONCE_ACTION_INTRADAY)) {
            return array('type' => 'error', 'text' => 'نشست فرم منقضی شده؛ دوباره تلاش کنید.');
        }
        if (!current_user_can(GC_License::MANAGE_CAPABILITY)) {
            return array('type' => 'error', 'text' => 'شما اجازه تغییر این تنظیمات را ندارید.');
        }

        $source = isset($_POST['goldcrawler_intraday_source'])
            ? sanitize_text_field(wp_unslash($_POST['goldcrawler_intraday_source'])) : 'auto';
        if (!in_array($source, array('auto', 'tgju', 'recorded'), true)) {
            $source = 'auto';
        }

        // A URL template, not a plain URL: esc_url_raw would strip the braces
        // our four placeholders need, so validate the shape by hand instead.
        $endpoint = isset($_POST['goldcrawler_intraday_endpoint'])
            ? trim(wp_unslash($_POST['goldcrawler_intraday_endpoint'])) : '';
        if ($endpoint !== '' && strpos($endpoint, '{symbol}') !== false) {
            if (!preg_match('#^https://[^\s<>"\']+$#', $endpoint)) {
                return array(
                    'type' => 'error',
                    'text' => 'آدرس سرویس باید با https:// شروع شود و شامل {symbol} باشد.',
                );
            }
        } elseif ($endpoint !== '') {
            // Otherwise it must name one of the known candidates.
            $names = array_column(GC_TGJU_Intraday::candidates(), 'name');
            if (!in_array($endpoint, $names, true)) {
                return array(
                    'type' => 'error',
                    'text' => 'آدرس نامعتبر است: یا خالی بگذارید، یا یک آدرس کامل https با {symbol} بنویسید.',
                );
            }
        }

        GC_Storage::update_settings(array(
            'intraday_source' => $source,
            'intraday_endpoint' => $endpoint,
        ));
        return array('type' => 'success', 'text' => 'تنظیمات سرویس درون‌روزی ذخیره شد.');
    }

    /** Runs the endpoint probe and reports the result inline. */
    private static function handle_probe_intraday() {
        if (!isset($_POST['goldcrawler_intraday_nonce'])
            || !wp_verify_nonce($_POST['goldcrawler_intraday_nonce'], self::NONCE_ACTION_INTRADAY)) {
            return array('type' => 'error', 'text' => 'نشست فرم منقضی شده؛ دوباره تلاش کنید.');
        }
        if (!current_user_can(GC_License::MANAGE_CAPABILITY)) {
            return array('type' => 'error', 'text' => 'شما اجازه اجرای این بررسی را ندارید.');
        }

        $result = GC_Crawler::probe_intraday(null);
        // Keep the whole attempt list: the *spacing* per resolution is the
        // diagnostic, since this endpoint answers an unrecognised resolution
        // with daily bars rather than an error.
        self::$probe_report = $result['attempts'];

        if (!empty($result['working'])) {
            return array(
                'type' => 'success',
                'text' => 'سرویس درون‌روزی کار می‌کند: ' . $result['working']['endpoint']
                    . ' (resolution=' . $result['working']['resolution'] . ') — ذخیره شد.',
            );
        }

        $daily_only = false;
        foreach ($result['attempts'] as $row) {
            if (!empty($row['candles']) && empty($row['intraday'])) {
                $daily_only = true;
                break;
            }
        }
        if ($daily_only) {
            return array(
                'type' => 'error',
                'text' => 'سرویس پاسخ می‌دهد، اما برای این نماد فقط داده «روزانه»'
                    . ' برمی‌گرداند و هیچ‌کدام از کدهای دقت درون‌روزی را نپذیرفت'
                    . ' (فاصله کندل‌ها در جدول زیر: ۸۶۴۰۰ ثانیه = یک روز). یعنی آدرس'
                    . ' نمودار درون‌روزی چیز دیگری است؛ آن را از DevTools بردارید و'
                    . ' در فیلد «آدرس سرویس نمودار» بگذارید.',
            );
        }
        return array(
            'type' => 'error',
            'text' => 'هیچ‌کدام از آدرس‌های شناخته‌شده پاسخ نداد. جدول زیر می‌گوید هر'
                . ' آدرس چه جوابی داد؛ آدرس درست را از DevTools بردارید و در فیلد'
                . ' «آدرس سرویس نمودار» بگذارید.',
        );
    }

    /** Renders what each probed endpoint/resolution actually returned. */
    private static function render_probe_report() {
        if (!self::$probe_report) {
            return;
        }
        echo '<h3>نتیجه بررسی سرویس</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>آدرس</th><th>دقت درخواستی</th><th>تعداد کندل</th>'
            . '<th>فاصله کندل‌ها</th><th>نتیجه</th></tr></thead><tbody>';
        foreach (self::$probe_report as $row) {
            $gap = isset($row['spacing_seconds']) ? $row['spacing_seconds'] : null;
            if ($gap === null) {
                $spacing = '—';
            } elseif ($gap % 86400 === 0) {
                $spacing = intdiv($gap, 86400) . ' روز (' . $gap . ' ثانیه)';
            } elseif ($gap % 60 === 0) {
                $spacing = intdiv($gap, 60) . ' دقیقه (' . $gap . ' ثانیه)';
            } else {
                $spacing = $gap . ' ثانیه';
            }
            if (!empty($row['ok'])) {
                $verdict = '✔ درون‌روزی';
            } elseif (isset($row['error'])) {
                $verdict = '✖ ' . $row['error'];
            } else {
                $verdict = '✖ بدون داده';
            }
            echo '<tr>'
                . '<td><code>' . esc_html($row['endpoint']) . '</code></td>'
                . '<td><code>' . esc_html($row['resolution']) . '</code></td>'
                . '<td>' . esc_html(isset($row['candles']) ? (string) $row['candles'] : '—') . '</td>'
                . '<td>' . esc_html($spacing) . '</td>'
                . '<td>' . esc_html($verdict) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">اگر همه ردیف‌ها «روزانه» هستند، این آدرس داده'
            . ' درون‌روزی ندارد. در مرورگر: F12 ← تب <b>Network</b> ← فیلتر'
            . ' <b>Fetch/XHR</b> ← روی نمودار بازه «۱ روز» را انتخاب کنید ← نشانی'
            . ' درخواست تازه را بردارید.</p>';
    }

    private static function render_intraday_section($notice) {
        $settings = GC_Storage::get_settings();
        $source = isset($settings['intraday_source']) ? $settings['intraday_source'] : 'auto';
        $endpoint = isset($settings['intraday_endpoint']) ? $settings['intraday_endpoint'] : '';

        echo '<hr><h2 class="title">سرویس قیمت درون‌روزی</h2>';
        echo '<p class="description">گزارش‌های «هر ۱۰ دقیقه / هر ۱ ساعت / هر تغییر قیمت»'
            . ' قیمت‌های همان بازه را از سرویس نمودار <code>tgju.org</code> استخراج می‌کنند.'
            . ' آن سرویس یک API رسمی و مستند نیست، پس افزونه چند آدرس شناخته‌شده را امتحان'
            . ' می‌کند و هرکدام جواب داد همان را ذخیره می‌کند. دکمه «بررسی سرویس» همین'
            . ' آزمایش را انجام می‌دهد.</p>';

        if ($notice) {
            $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>'
                . esc_html($notice['text']) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION_INTRADAY, 'goldcrawler_intraday_nonce');

        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="goldcrawler-intraday-source">منبع داده</label></th><td>';
        echo '<select id="goldcrawler-intraday-source" name="goldcrawler_intraday_source">';
        foreach (array(
            'auto' => 'خودکار (اول TGJU، بعد نمونه‌های ثبت‌شده)',
            'tgju' => 'فقط استخراج از TGJU',
            'recorded' => 'فقط نمونه‌های ثبت‌شده روی هاست',
        ) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($source, $value, false)
                . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">«خودکار» پیشنهاد می‌شود. اگر می‌خواهید مطمئن شوید داده'
            . ' واقعاً از TGJU می‌آید و خطا پنهان نمی‌شود، «فقط استخراج از TGJU» را بگذارید.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="goldcrawler-intraday-endpoint">آدرس سرویس نمودار</label></th><td>';
        echo '<input type="text" id="goldcrawler-intraday-endpoint" name="goldcrawler_intraday_endpoint"'
            . ' class="large-text code" dir="ltr" value="' . esc_attr($endpoint) . '"'
            . ' placeholder="خودکار — خالی بگذارید">';
        echo '<p class="description">خالی بگذارید تا افزونه خودش آدرس‌های شناخته‌شده را امتحان کند.'
            . ' اگر بررسی هیچ آدرسی پیدا نکرد، آدرس درست را از DevTools مرورگر بردارید'
            . ' (تب <b>Network</b>، فیلتر <b>Fetch/XHR</b>، هنگام بازکردن نمودار درون‌روزی در'
            . ' tgju.org) و اینجا بگذارید. به‌جای مقادیر، این چهار متغیر را بنویسید:'
            . ' <code dir="ltr">{symbol}</code>، <code dir="ltr">{resolution}</code>،'
            . ' <code dir="ltr">{from}</code> و <code dir="ltr">{to}</code>.<br>نمونه:'
            . ' <code dir="ltr">https://platform.tgju.org/fa/tvdata/history?symbol={symbol}&amp;resolution={resolution}&amp;from={from}&amp;to={to}</code></p>';
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<p class="submit">'
            . '<button type="submit" name="goldcrawler_save_intraday" class="button button-primary">ذخیره</button> '
            . '<button type="submit" name="goldcrawler_probe_intraday" class="button">بررسی سرویس</button>'
            . '</p>';
        echo '</form>';

        self::render_probe_report();
    }

    private static function render_symbols_section($notice) {
        $settings = GC_Storage::get_settings();
        $disabled = array_flip((array) $settings['disabled_symbols']);
        $customs = (array) $settings['custom_symbols'];

        echo '<hr style="margin:32px 0;">';
        echo '<h1>مدیریت نمادهای TGJU</h1>';
        echo '<p>از این بخش می‌توانید نمادهای پیش‌فرض را کم یا زیاد کنید و نمادهای دلخواه خودتان'
            . ' را از سایت <code>tgju.org</code> به ابزار اضافه کنید. تغییرات این بخش برای همه'
            . ' کاربران ابزار یکسان است (مشترک بین همه، نه جداگانه برای هر کاربر).</p>';

        if ($notice) {
            $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notice['text']) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION_SYMBOLS, 'goldcrawler_symbols_nonce');

        // -- بخش ۱: فعال/غیرفعال کردن نمادهای پیش‌فرض ---------------------------
        echo '<h2 class="title">نمادهای پیش‌فرض (کم‌کردن)</h2>';
        echo '<p class="description">تیک هر نمادی که نمی‌خواهید کاربران ببینند و دریافت شود را بردارید.'
            . ' نمادهای غیرفعال از نوار کناری ابزار و از دریافت داده‌ها حذف می‌شوند، اما هر زمان'
            . ' بخواهید می‌توانید دوباره تیک بزنید و فعالشان کنید.</p>';
        echo '<p><input type="search" id="goldcrawler-symbol-search" placeholder="جست‌وجوی نماد پیش‌فرض..." '
            . 'style="max-width:320px" onkeyup="goldcrawlerFilterSymbols(this.value)"></p>';
        echo '<table class="widefat striped" id="goldcrawler-symbol-table"><thead><tr>'
            . '<th style="width:40px"></th><th>شناسه (Key)</th><th>نام نمایشی</th><th>گروه</th><th>واحد پول</th>'
            . '</tr></thead><tbody>';
        foreach (GC_Symbols::catalog() as $key => $def) {
            $checked = !isset($disabled[$key]);
            echo '<tr data-name="' . esc_attr(strtolower($key . ' ' . $def['name'])) . '">';
            echo '<td><input type="checkbox" name="goldcrawler_enabled[]" value="' . esc_attr($key) . '"' . ($checked ? ' checked' : '') . '></td>';
            echo '<td><code>' . esc_html($key) . '</code></td>';
            echo '<td>' . esc_html($def['name']) . '</td>';
            echo '<td>' . esc_html($def['group']) . '</td>';
            echo '<td>' . esc_html(GC_Symbols::unit_label($def['currency'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // -- بخش ۲: نمادهای دلخواهِ ذخیره‌شده (حذف) -----------------------------
        echo '<h2 class="title">نمادهای دلخواه فعلی</h2>';
        if (!$customs) {
            echo '<p class="description">هنوز هیچ نماد دلخواهی اضافه نکرده‌اید. از فرم پایین همین صفحه یکی اضافه کنید،'
                . ' یا از داخل خود ابزار (بخش «افزودن نماد دلخواه از TGJU» در نوار کناری) هر کاربر مجاز می‌تواند اضافه کند.</p>';
        } else {
            echo '<p class="description">برای حذف یک نماد دلخواه، تیک ستون «حذف» آن را بزنید و «ذخیره» را بزنید.</p>';
            echo '<table class="widefat striped"><thead><tr>'
                . '<th style="width:40px">حذف</th><th>شناسه (Key)</th><th>نام نمایشی</th><th>گروه</th><th>واحد پول</th>'
                . '</tr></thead><tbody>';
            foreach ($customs as $c) {
                $ckey = (string) ($c['key'] ?? '');
                if ($ckey === '') {
                    continue;
                }
                echo '<tr>';
                echo '<td><input type="checkbox" name="goldcrawler_delete_custom[]" value="' . esc_attr($ckey) . '"></td>';
                echo '<td><code>' . esc_html($ckey) . '</code></td>';
                echo '<td>' . esc_html((string) ($c['name'] ?? $ckey)) . '</td>';
                echo '<td>' . esc_html((string) ($c['group'] ?? 'نمادهای دلخواه')) . '</td>';
                echo '<td>' . esc_html(GC_Symbols::unit_label((string) ($c['currency'] ?? 'IRR'))) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // -- بخش ۳: افزودن نماد دلخواه تازه (زیادکردن) --------------------------
        echo '<h2 class="title">افزودن نماد دلخواه تازه (زیاد‌کردن)</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="gc-new-key">شناسه نماد (Key)</label></th><td>'
            . '<input type="text" id="gc-new-key" name="goldcrawler_new_key" class="regular-text" placeholder="مثلاً price_dollar_rl">'
            . '<p class="description">این مقدار باید <strong>دقیقاً</strong> همان بخش انتهایی نشانی صفحه‌ی این نماد در سایت'
            . ' <code>tgju.org</code> باشد. مثلاً برای صفحه‌ی <code>https://www.tgju.org/profile/price_dollar_rl</code>'
            . ' مقدار <code>price_dollar_rl</code> را وارد کنید. فقط حروف انگلیسی، عدد، «-»، «_» و «.» مجاز است (۲ تا ۶۴ کاراکتر).'
            . ' اگر این شناسه از قبل جزو نمادهای پیش‌فرض <em>فعال</em> در جدول بالا باشد، این افزودن نادیده گرفته می‌شود؛'
            . ' یا شناسه دیگری انتخاب کنید یا ابتدا آن نماد پیش‌فرض را از فهرست بالا غیرفعال کنید. این فیلد را خالی بگذارید'
            . ' اگر فقط می‌خواهید حذف/فعال‌سازی بخش‌های بالا را ذخیره کنید.</p></td></tr>';

        echo '<tr><th scope="row"><label for="gc-new-name">نام نمایشی</label></th><td>'
            . '<input type="text" id="gc-new-name" name="goldcrawler_new_name" class="regular-text" placeholder="مثلاً دلار آمریکا">'
            . '<p class="description">همان نامی که به فارسی در فهرست نمادها، نمودار و گزارش‌های خروجی نمایش داده می‌شود.'
            . ' اگر خالی بگذارید، همان شناسه (Key) به‌عنوان نام نمایش داده می‌شود.</p></td></tr>';

        echo '<tr><th scope="row"><label for="gc-new-group">گروه</label></th><td>'
            . '<input type="text" id="gc-new-group" name="goldcrawler_new_group" class="regular-text" placeholder="مثلاً ارز، طلا و نقره، یا هر عنوان دلخواه">'
            . '<p class="description">برای دسته‌بندی و فیلترکردن این نماد در نوار کناری ابزار استفاده می‌شود؛ هر متنی که بخواهید'
            . ' می‌توانید بنویسید. اگر خالی بگذارید، «نمادهای دلخواه» در نظر گرفته می‌شود.</p></td></tr>';

        echo '<tr><th scope="row"><label for="gc-new-currency">واحد پول</label></th><td>'
            . '<select id="gc-new-currency" name="goldcrawler_new_currency">'
            . '<option value="IRR">تومان</option><option value="USD">دلار</option></select>'
            . '<p class="description">اگر قیمت این نماد در TGJU به ریال نمایش داده می‌شود، «تومان» را انتخاب کنید'
            . ' (افزونه خودش تقسیم بر ۱۰ روی ریال را انجام می‌دهد تا به تومان تبدیل شود). اگر قیمت اصلی آن دلاری است'
            . ' (مثلاً انس جهانی طلا/نقره یا رمزارزها)، «دلار» را انتخاب کنید.</p></td></tr>';

        echo '<tr><th scope="row"><label for="gc-new-decimals">تعداد رقم اعشار</label></th><td>'
            . '<input type="number" id="gc-new-decimals" name="goldcrawler_new_decimals" min="0" max="8" value="0" style="width:80px">'
            . '<p class="description">برای بیشتر قیمت‌های تومانی مقدار «۰» مناسب است. برای قیمت‌های دلاری معمولاً «۲»،'
            . ' و برای رمزارزهای کم‌ارزش مثل تتر می‌توانید «۴» بگذارید.</p></td></tr>';

        echo '</tbody></table>';

        echo '<p class="submit"><button type="submit" name="goldcrawler_save_symbols" class="button button-primary">ذخیره نمادها</button></p>';
        echo '</form>';
        echo '<script>function goldcrawlerFilterSymbols(q){q=q.toLowerCase();document.querySelectorAll("#goldcrawler-symbol-table tbody tr").forEach(function(r){r.style.display=r.dataset.name.indexOf(q)>-1?"":"none";});}</script>';
    }
}
