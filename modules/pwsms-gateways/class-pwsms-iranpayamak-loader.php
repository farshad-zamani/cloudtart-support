<?php

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

class CloudTart_PWSMS_IranPayamak_Loader
{
    private $gateway_class = 'PW\\PWSMS\\Gateways\\CloudTartIranPayamakFarazSMS';
    private $gateway_id = 'cloudtart_iranpayamak_farazsms';

    public function __construct()
    {
        if (!class_exists('PW\\PWSMS\\Gateways\\Gateway')) {
            return;
        }

        require_once plugin_dir_path(__FILE__) . 'class-pwsms-iranpayamak-gateway.php';

        add_filter('pwoosms_sms_gateways', [$this, 'register_gateway']);
        add_filter('pwoosms_main_settings', [$this, 'register_gateway_settings']);
        add_filter('pwoosms_settings_fields', [$this, 'append_pattern_field_help']);
        add_filter('admin_body_class', [$this, 'add_admin_body_class']);
        add_action('admin_init', [$this, 'ensure_default_sender']);
        add_action('admin_head', [$this, 'print_admin_settings_css'], 5);
        add_action('pwoosms_settings_form_top_sms_main_settings', [$this, 'render_gateway_visibility_script'], 20);
        add_action('pwoosms_settings_form_top_sms_buyer_settings', [$this, 'render_pattern_guide_card'], 5);
        add_action('pwoosms_settings_form_top_sms_notif_settings', [$this, 'render_pattern_guide_card'], 5);
        add_action('pwoosms_settings_form_top_sms_product_admin_settings', [$this, 'render_pattern_guide_card'], 5);
        add_action('pwoosms_settings_form_top_sms_super_admin_settings', [$this, 'render_pattern_guide_card'], 5);
    }

    public function register_gateway($gateways)
    {
        if (class_exists($this->gateway_class)) {
            $gateways[$this->gateway_class] = __('IranPayamak - FarazSMS - iranpayamak - farazsms', 'cloudtart-support');
        }

        return $gateways;
    }

    public function register_gateway_settings($settings)
    {
        if (!is_array($settings)) {
            $settings = [];
        }

        $fields = [
            [
                'name'  => 'cloudtart_iranpayamak_apikey',
                'label' => __('IranPayamak API Key', 'cloudtart-support'),
                'type'  => 'text',
                'ltr'   => true,
                'desc'  => __('Only for the "IranPayamak - FarazSMS" gateway. To obtain this key, go to Developers > Access Keys in the IranPayamak panel.', 'cloudtart-support'),
            ],
        ];

        $new_settings = [];
        $inserted = false;

        foreach ((array) $settings as $setting) {
            if (isset($setting['name']) && $setting['name'] === 'sms_gateway_sender') {
                $setting['default'] = '90008361';
            }

            $new_settings[] = $setting;

            if (!$inserted && isset($setting['name']) && $setting['name'] === 'sms_gateway_sender') {
                $new_settings = array_merge($new_settings, $fields);
                $inserted = true;
            }
        }

        if (!$inserted) {
            $new_settings = array_merge($new_settings, $fields);
        }

        return $new_settings;
    }

    public function append_pattern_field_help($settings_fields)
    {
        if (!is_array($settings_fields)) {
            return $settings_fields;
        }

        $sections = [
            'sms_buyer_settings',
            'sms_notif_settings',
            'sms_product_admin_settings',
            'sms_super_admin_settings',
        ];

        foreach ($sections as $section) {
            if (empty($settings_fields[$section]) || !is_array($settings_fields[$section])) {
                continue;
            }

            foreach ($settings_fields[$section] as $index => $field) {
                if (
                    !is_array($field) ||
                    (isset($field['type']) && $field['type'] !== 'textarea')
                ) {
                    continue;
                }

                $name = isset($field['name']) ? (string) $field['name'] : '';
                if (
                    strpos($name, 'sms_body_') !== 0 &&
                    strpos($name, 'super_admin_sms_body_') !== 0 &&
                    strpos($name, 'product_admin_sms_body_') !== 0 &&
                    strpos($name, 'notif_') !== 0 &&
                    strpos($name, 'admin_') !== 0
                ) {
                    continue;
                }

                $help = __('Suggested format for IranPayamak pattern messages: first line `pcode:PATTERN_CODE`, then one `key:{shortcode}` per following line.', 'cloudtart-support')
                    . '<br>' . __('Example:', 'cloudtart-support')
                    . '<br>pcode:3RAtbRBZZi'
                    . '<br>b_first_name:{b_first_name}'
                    . '<br>order_id:{order_id}';

                $current_desc = isset($field['desc']) ? (string) $field['desc'] : '';
                $field['desc'] = trim($current_desc) === '' ? $help : ($current_desc . '<br><br>' . $help);
                $settings_fields[$section][$index] = $field;
            }
        }

        return $settings_fields;
    }

    public function ensure_default_sender()
    {
        $main_settings = get_option('sms_main_settings');
        if (!is_array($main_settings)) {
            $main_settings = [];
        }

        if (empty($main_settings['sms_gateway_sender'])) {
            $main_settings['sms_gateway_sender'] = '90008361';
            update_option('sms_main_settings', $main_settings);
        }
    }

    public function render_gateway_visibility_script()
    {
        ?>
        <script>
            jQuery(function ($) {
                var gatewayClass = '<?php echo esc_js($this->gateway_class); ?>';
                var gatewayId = '<?php echo esc_js($this->gateway_id); ?>';

                function rowByFieldName(fieldName) {
                    return $('[name="sms_main_settings[' + fieldName + ']"]').closest('tr');
                }

                function normalizeGatewayValue(value) {
                    value = String(value || '').trim();
                    value = value.replace(/^\\+/, '');

                    return value;
                }

                function ensureDefaultSender() {
                    var senderInput = $('[name="sms_main_settings[sms_gateway_sender]"]');
                    if (senderInput.length && $.trim(senderInput.val()) === '') {
                        senderInput.val('90008361');
                    }
                }

                function toggleIranPayamakFields() {
                    var gatewayValue = normalizeGatewayValue($('[name="sms_main_settings[sms_gateway]"]').val());
                    var isIranPayamak = (
                        gatewayValue === gatewayClass ||
                        gatewayValue === gatewayId ||
                        gatewayValue.indexOf('CloudTartIranPayamakFarazSMS') !== -1
                    );
                    $('body').toggleClass('cloudtart-pwsms-iranpayamak-active', isIranPayamak);

                    var usernameRow = rowByFieldName('sms_gateway_username');
                    var passwordRow = rowByFieldName('sms_gateway_password');
                    var senderRow = rowByFieldName('sms_gateway_sender');
                    var apiKeyRow = rowByFieldName('cloudtart_iranpayamak_apikey');

                    if (isIranPayamak) {
                        usernameRow.hide();
                        passwordRow.hide();
                        senderRow.show();
                        apiKeyRow.show();
                        ensureDefaultSender();
                    } else {
                        usernameRow.show();
                        passwordRow.show();
                        senderRow.show();
                        apiKeyRow.hide();
                    }
                }

                toggleIranPayamakFields();
                setTimeout(toggleIranPayamakFields, 250);
                $(document).on('change', '[name="sms_main_settings[sms_gateway]"]', toggleIranPayamakFields);
            });
        </script>
        <?php
    }

    public function print_admin_settings_css()
    {
        if (!$this->is_pwsms_settings_page()) {
            return;
        }

        ?>
        <style>
            #sms_buyer_settings textarea,
            #sms_notif_settings textarea,
            #sms_product_admin_settings textarea,
            #sms_super_admin_settings textarea {
                direction: ltr !important;
                text-align: left !important;
                unicode-bidi: plaintext;
            }
            .cloudtart-iranpayamak-guide {
                margin: 10px auto 16px auto;
                max-width: 980px;
                border-radius: 12px;
                padding: 14px 16px;
                color: #0f172a;
                background: linear-gradient(130deg, #3ED39E 0%, #577EC0 58%, #23A174 100%);
                box-shadow: 0 8px 18px rgba(35, 161, 116, 0.18);
            }
            .cloudtart-iranpayamak-guide h3 {
                margin: 0 0 8px 0;
                color: #07121c;
                font-size: 16px;
                font-weight: 700;
                text-align: center;
            }
            .cloudtart-iranpayamak-guide p {
                margin: 5px 0;
                color: #07121c;
                font-size: 13px;
                line-height: 1.9;
            }
            .cloudtart-iranpayamak-guide code {
                background: rgba(255, 255, 255, 0.35);
                border-radius: 4px;
                padding: 1px 6px;
                direction: ltr;
                unicode-bidi: plaintext;
                display: inline-block;
            }
            .cloudtart-iranpayamak-guide .cloudtart-pattern-example {
                margin-top: 8px;
                padding: 10px;
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.34);
                direction: ltr;
                text-align: left;
                unicode-bidi: plaintext;
                font-family: Consolas, Monaco, monospace;
                white-space: pre-line;
            }
            body.cloudtart-pwsms-iranpayamak-active #sms_main_settings tr:has([name="sms_main_settings[sms_gateway_username]"]),
            body.cloudtart-pwsms-iranpayamak-active #sms_main_settings tr:has([name="sms_main_settings[sms_gateway_password]"]) {
                display: none !important;
            }
        </style>
        <?php
    }

    public function render_pattern_guide_card()
    {
        ?>
        <div class="cloudtart-iranpayamak-guide">
            <h3><?php echo esc_html__('Quick guide to IranPayamak pattern messages', 'cloudtart-support'); ?></h3>
            <p>
                <?php
                printf(
                    wp_kses(
                        __('To send a pattern message, write %1$s (the approved pattern code) on the first line of each text box, then add variables in %2$s format on the next lines.', 'cloudtart-support'),
                        ['code' => []]
                    ),
                    '<code>pcode</code>',
                    '<code>key:value</code>'
                );
                ?>
            </p>
            <p>
                <?php
                printf(
                    wp_kses(
                        __('The left side (key) must exactly match the variable name defined in the IranPayamak panel; the right side (value) can be a WooCommerce shortcode such as %1$s or %2$s.', 'cloudtart-support'),
                        ['code' => []]
                    ),
                    '<code>{order_id}</code>',
                    '<code>{b_first_name}</code>'
                );
                ?>
            </p>
            <div class="cloudtart-pattern-example">pcode:3RAtbRBZZi
b_first_name:{b_first_name}
order_id:{order_id}</div>
        </div>
        <?php
    }

    private function is_pwsms_settings_page()
    {
        if (!is_admin()) {
            return false;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === 'persian-woocommerce-sms-pro';
    }

    private function is_iranpayamak_selected()
    {
        $gateway = (string) PWSMS()->get_option('sms_gateway');
        $gateway = ltrim(trim($gateway), '\\');

        return (
            $gateway === ltrim($this->gateway_class, '\\') ||
            $gateway === $this->gateway_id ||
            stripos($gateway, 'CloudTartIranPayamakFarazSMS') !== false
        );
    }

    public function add_admin_body_class($classes)
    {
        if (!$this->is_pwsms_settings_page()) {
            return $classes;
        }

        if ($this->is_iranpayamak_selected()) {
            $classes .= ' cloudtart-pwsms-iranpayamak-active';
        }

        return $classes;
    }
}
