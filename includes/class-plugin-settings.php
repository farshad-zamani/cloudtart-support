<?php
/**
 * Shared module settings for runtime loading and the admin form.
 */
if (!defined('ABSPATH')) {
    exit;
}

class CloudTart_Support_Plugin_Settings {
    public static function all() {
        $stored = get_option('cloudtart_plugins_settings', []);

        // Migrate before applying defaults: a default '0' would mask a legacy '1'.
        // Reading settings does not write options or override an explicit choice.
        return wp_parse_args(self::migrate_legacy(is_array($stored) ? $stored : []), [
            'enable_digits_iranpayamak' => '0',
            'enable_dokan_fix' => '0',
            'enable_wallet_withdraw_orders' => '0',
            'enable_pwsms_iranpayamak' => '0',
            'enable_wc_audio_stream' => '0',
            'wc_audio_allow_download' => '0',
            'wc_audio_show_player' => '1',
            'wc_audio_player_theme' => 'dark',
        ]);
    }

    public static function migrate_legacy(array $settings) {
        if (!isset($settings['enable_digits_iranpayamak']) && isset($settings['enable_digits_ippanel'])) {
            $settings['enable_digits_iranpayamak'] = $settings['enable_digits_ippanel'];
        }

        if (isset($settings['enable_digits_iranpayamak'])) {
            $settings['enable_digits_iranpayamak'] = in_array($settings['enable_digits_iranpayamak'], ['1', 1, true], true) ? '1' : '0';
        }

        return $settings;
    }
}
