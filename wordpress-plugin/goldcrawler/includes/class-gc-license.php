<?php
/**
 * Per-user access control ("license") for the [gold_crawler] shortcode.
 *
 * This is a manual allowlist the site admin curates from an admin page
 * (see GC_Admin) - not a payment/checkout system. It stores a plain list of
 * WordPress user IDs in a single option; anyone in that list (plus admins,
 * always) can see and use the tool. Everyone else - including a logged-in
 * user who simply hasn't been granted a license - is turned away.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GC_License {

    const OPTION = 'goldcrawler_licensed_users';
    const MANAGE_CAPABILITY = 'manage_options'; // who may grant/revoke licenses

    /** @return int[] */
    public static function licensed_user_ids() {
        $ids = get_option(self::OPTION, array());
        if (!is_array($ids)) {
            return array();
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @param int[] $ids */
    public static function set_licensed_user_ids($ids) {
        $clean = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        update_option(self::OPTION, $clean);
        return $clean;
    }

    public static function grant($user_id) {
        $ids = self::licensed_user_ids();
        $ids[] = (int) $user_id;
        return self::set_licensed_user_ids($ids);
    }

    public static function revoke($user_id) {
        $ids = array_diff(self::licensed_user_ids(), array((int) $user_id));
        return self::set_licensed_user_ids($ids);
    }

    public static function is_licensed($user_id) {
        return in_array((int) $user_id, self::licensed_user_ids(), true);
    }

    /**
     * The single access check used by both the shortcode and the AJAX
     * handlers: an Administrator always has access (so the site owner can
     * never lock themselves out); anyone else needs to be logged in AND
     * explicitly licensed.
     */
    public static function current_user_allowed() {
        if (!is_user_logged_in()) {
            return false;
        }
        if (current_user_can(self::MANAGE_CAPABILITY)) {
            return true;
        }
        return self::is_licensed(get_current_user_id());
    }
}
