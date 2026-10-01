<?php

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

class CloudTart_Digits_IPPanel_Loader {

    public function __construct() {
        add_filter('digits_sms_gateways', [$this, 'gateway_list']);
        add_filter('unitedover_send_sms', [$this, 'send_sms'], 10, 7);
        add_action('admin_menu', [$this, 'add_about_submenu'], 20);
    }

    /**
     * افزودن زیرمنوی "درباره کلادتارت" به منوی Digits
     */
    public function add_about_submenu() {
        // تلاش برای افزودن به منوی digits_settings (اسلاگ استاندارد)
        add_submenu_page(
            'digits_settings',
            __('About CloudTart', 'cloudtart-support'),
            __('About CloudTart', 'cloudtart-support'),
            'manage_options',
            'cloudtart-about-digits',
            [$this, 'redirect_to_about_tab']
        );

        // تلاش برای افزودن به منوی digits (در صورت تغییر اسلاگ در نسخه‌های دیگر)
        add_submenu_page(
            'digits',
            __('About CloudTart', 'cloudtart-support'),
            __('About CloudTart', 'cloudtart-support'),
            'manage_options',
            'cloudtart-about-digits-alt',
            [$this, 'redirect_to_about_tab']
        );
    }

    /**
     * ریدایرکت به تب "درباره ما" در پلاگین پشتیبانی
     */
    public function redirect_to_about_tab() {
        wp_safe_redirect(admin_url('options-general.php?page=cloudtart-support&tab=about'));
        exit;
    }

    public function gateway_list($gateways) {
        $gateways['iranpayamak'] = array(
            'value'  => 1000,
            'label'  => __('IranPayamak (FarazSMS API)', 'cloudtart-support'),
            'inputs' => array(
                __('IranPayamak API Key', 'digits') => array(
                    'text' => true,
                    'name' => 'apikey',
                    'desc' => __('To get an API key, go to Developers > Access Keys in the IranPayamak panel.', 'cloudtart-support')
                ),
                __('Sender', 'digits') => array(
                    'text' => true,
                    'name' => 'sender',
                    'value' => '90008361',
                    'desc' => __('Enter your service sender line number. Default: 90008361', 'cloudtart-support')
                ),
                __('Send by pattern', 'digits') => array(
                    'options'  => array(
                        __('No', 'digits')  => 0,
                        __('Yes', 'digits') => 1
                    ),
                    'name'     => 'pattern',
                    'optional' => 1,
                    'desc'     => __('Enable this to send OTP using a pattern template.', 'cloudtart-support')
                ),
                __('Pattern Code', 'digits') => array(
                    'text'     => true,
                    'name'     => 'patterncode',
                    'optional' => 1,
                    'desc'     => __('Enter the pattern code (e.g., abc12345).', 'cloudtart-support')
                ),
                __('Pattern Variable', 'digits') => array(
                    'text'     => true,
                    'name'     => 'patternvars',
                    'optional' => 1,
                    'desc'     => __('Pattern variable name (e.g., verification-code).', 'cloudtart-support') . '<script>
jQuery(document).ready(function($) {
    function togglePatternFields(animate) {
        var patternSelect   = $("#iranpayamak_pattern");
        var patterncodeRow  = $("#iranpayamak_patterncode").closest("tr");
        var patternvarsRow  = $("#iranpayamak_patternvars").closest("tr");

        if (patternSelect.length && patterncodeRow.length && patternvarsRow.length) {
            var patternValue = patternSelect.val();
            if (patternValue == "1") {
                if (animate) {
                    patterncodeRow.slideDown(500);
                    patternvarsRow.slideDown(500);
                } else {
                    patterncodeRow.show();
                    patternvarsRow.show();
                }
            } else {
                if (animate) {
                    patterncodeRow.slideUp(500);
                    patternvarsRow.slideUp(500);
                } else {
                    patterncodeRow.hide();
                    patternvarsRow.hide();
                }
            }
        }
    }

    setTimeout(function() {
        togglePatternFields(false);
    }, 500);

    $(document).on("change", "#iranpayamak_pattern", function() { togglePatternFields(true); });
});
</script>'
                ),
            )
        );
        return $gateways;
    }

    public function send_sms($value, $option_slug, $gateway_id, $countrycode, $mobile, $messagetemplate, $testCall) {
        if ($gateway_id != 1000) {
            return $value;
        }

        require_once plugin_dir_path(__FILE__) . 'class-ippanel.php';

        $ippanelSettings = get_option('digit_iranpayamak');
        if (empty($ippanelSettings)) {
            $ippanelSettings = get_option('digit_ippanel');
        }
        if (empty($ippanelSettings)) {
            if ($testCall) return __('IranPayamak gateway settings not found.', 'cloudtart-support');
            return false;
        }

        if (empty($ippanelSettings['sender'])) {
            $ippanelSettings['sender'] = '90008361';
        }

        $ippanel = new Digits_IranPayamak_API($ippanelSettings);

        // Attempt to get OTP
        $otp = '';
        if (defined('DIGITS_OTP')) {
            $otp = DIGITS_OTP;
        }

        // If pattern is enabled
        $pattern = isset($ippanelSettings['pattern']) ? $ippanelSettings['pattern'] : 0;

        $param = array(
            'otp'     => $otp,
            'to'      => $mobile,
            'message' => $messagetemplate,
        );

        if ($pattern == 1) {
            $result = $ippanel->sendPattern($param);
        } else {
            $result = $ippanel->send($param);
        }

        $response = json_decode($result, true);

        $success_message = __('Send successful: ', 'cloudtart-support');
        $fault_message   = __('Send failed: ', 'cloudtart-support');

        // Analyze response
        if (isset($response['meta']['status']) &&
            $response['meta']['status'] === true &&
            isset($response['data']['message_outbox_ids'])) {

            if ($testCall) {
                return $success_message . $response['meta']['message'] . " - ID: " . $response['data']['message_outbox_ids'][0];
            }
            return true;
        } else {
            $error_message = isset($response['meta']['message']) ? $response['meta']['message'] : __('Unknown error', 'cloudtart-support');
            if ($testCall) {
                return $fault_message . $error_message . " (Response: " . $result . ")";
            }
            return false;
        }
    }
}
