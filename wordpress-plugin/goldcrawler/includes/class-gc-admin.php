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

    public static function register() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
    }

    public static function add_menu() {
        add_options_page(
            'دسترسی GoldCrawler',
            'GoldCrawler',
            GC_License::MANAGE_CAPABILITY,
            self::PAGE_SLUG,
            array(__CLASS__, 'render_page')
        );
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

    public static function render_page() {
        if (!current_user_can(GC_License::MANAGE_CAPABILITY)) {
            wp_die('شما اجازه دسترسی به این صفحه را ندارید.');
        }

        $notice = null;
        if (isset($_POST['goldcrawler_save']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $notice = self::handle_save();
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
        echo '</div>';
    }
}
