<?php
/**
 * کلاس مدیریت پنل ادمین
 */

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

class CloudTart_Support_Admin {
    private $logger;
    private $monitoring;
    private $expiry_manager;
    private $settings;
    private $expiry_settings;
    private $monitoring_settings;
    private $plugins_settings;
    private $internal_cdn_settings;

    /**
     * سازنده کلاس
     */
    public function __construct($logger, $monitoring, $expiry_manager) {
        $this->logger = $logger;
        $this->monitoring = $monitoring;
        $this->expiry_manager = $expiry_manager;
        
        // بارگذاری تنظیمات
        $this->load_settings();
        
        // راه‌اندازی هوک‌ها
        $this->setup_hooks();
    }

    /**
     * بارگذاری تنظیمات
     */
    private function load_settings() {
        $this->settings = get_option('cloudtart_support_settings', [
            'api_key' => '',
            'debug' => '0'
        ]);
        
        $this->expiry_settings = get_option('cloudtart_expiry_settings', [
            'domain_expiry' => '',
            'domain_period' => '1',
            'hosting_expiry' => '',
            'hosting_period' => '1',
            'notification_emails' => '',
            'notification_days' => '10'
        ]);
        
        $this->monitoring_settings = get_option('cloudtart_monitoring_settings', [
            'status_monitoring' => '1',
            'error_monitoring' => '1',
            'notification_email' => '',
            'check_interval' => 'hourly'
        ]);

        $this->plugins_settings = wp_parse_args(
            get_option('cloudtart_plugins_settings', []),
            [
                'enable_digits_iranpayamak' => '0',
                'enable_dokan_fix' => '0',
                'enable_wallet_withdraw_orders' => '0',
                'enable_pwsms_iranpayamak' => '0',
                'enable_wc_audio_stream' => '0',
                'wc_audio_allow_download' => '0',
                'wc_audio_show_player' => '1',
                'wc_audio_player_theme' => 'dark',
            ]
        );

        $this->internal_cdn_settings = wp_parse_args(
            get_option('cloudtart_internal_cdn_settings', []),
            $this->get_default_internal_cdn_settings()
        );

        // مهاجرت سازگار با تنظیمات قبلی
        if (
            !isset($this->plugins_settings['enable_digits_iranpayamak']) &&
            isset($this->plugins_settings['enable_digits_ippanel'])
        ) {
            $this->plugins_settings['enable_digits_iranpayamak'] = $this->plugins_settings['enable_digits_ippanel'] === '1' ? '1' : '0';
        }
    }

    private function get_default_internal_cdn_settings() {
        return CloudTart_Internal_CDN::default_settings();
    }

    private function get_internal_cdn_upload_dir() {
        $upload_dir = wp_upload_dir();
        $basedir = trailingslashit($upload_dir['basedir']) . 'cloudtart-support-cdn';
        $baseurl = trailingslashit($upload_dir['baseurl']) . 'cloudtart-support-cdn';

        return [
            'basedir' => $basedir,
            'baseurl' => $baseurl,
        ];
    }

    private function get_internal_cdn_allowed_upload_mimes() {
        return [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'html' => 'text/html',
            'htm' => 'text/html',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            'json' => 'application/json',
        ];
    }

    private function get_internal_cdn_log_file_info() {
        if (function_exists('cloudtart_support_get_internal_cdn_log_file')) {
            return cloudtart_support_get_internal_cdn_log_file();
        }

        return [
            'basedir' => WP_CONTENT_DIR,
            'file_path' => WP_CONTENT_DIR . '/cdn-replacer-blocked.log',
        ];
    }

    private function normalize_cdn_replacement_value($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $value) || strpos($value, '/') === 0) {
            return esc_url_raw($value);
        }

        $bundle_dir = CLOUDTART_SUPPORT_DIR . 'assets/local-cdn-assets';
        $upload_info = $this->get_internal_cdn_upload_dir();

        // sanitize_file_name() نام‌های چندنقطه‌ای را خراب می‌کرد (clipboard.min.js ← clipboard.min_.js).
        if (strpos($value, 'bundle:') === 0 || strpos($value, 'upload:') === 0) {
            list($type, $filename) = array_pad(explode(':', $value, 2), 2, '');
            $filename = CloudTart_Internal_CDN::safe_asset_name($filename, $type === 'bundle' ? $bundle_dir : $upload_info['basedir']);
            return $filename === '' ? '' : $type . ':' . $filename;
        }

        $filename = CloudTart_Internal_CDN::safe_asset_name($value, $bundle_dir);
        if ($filename === '') {
            return '';
        }

        if (file_exists($bundle_dir . '/' . $filename)) {
            return 'bundle:' . $filename;
        }

        $uploaded = CloudTart_Internal_CDN::safe_asset_name($value, $upload_info['basedir']);
        if ($uploaded !== '' && file_exists(trailingslashit($upload_info['basedir']) . $uploaded)) {
            return 'upload:' . $uploaded;
        }

        return 'bundle:' . $filename;
    }

    private function get_local_cdn_files() {
        $files = [];

        $bundled_dir = CLOUDTART_SUPPORT_DIR . 'assets/local-cdn-assets';
        if (is_dir($bundled_dir)) {
            foreach ((array) scandir($bundled_dir) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                if (!is_file($bundled_dir . DIRECTORY_SEPARATOR . $item)) {
                    continue;
                }

                $files[] = [
                    'value' => 'bundle:' . $item,
                    'label' => $item . ' (' . __('Plugin default', 'cloudtart-support') . ')',
                    'filename' => $item,
                    'source' => 'bundle',
                    'url' => CLOUDTART_SUPPORT_URL . 'assets/local-cdn-assets/' . rawurlencode($item),
                ];
            }
        }

        $upload_info = $this->get_internal_cdn_upload_dir();
        if (is_dir($upload_info['basedir'])) {
            foreach ((array) scandir($upload_info['basedir']) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                if (!is_file($upload_info['basedir'] . DIRECTORY_SEPARATOR . $item)) {
                    continue;
                }

                $files[] = [
                    'value' => 'upload:' . $item,
                    'label' => $item . ' (' . __('User upload', 'cloudtart-support') . ')',
                    'filename' => $item,
                    'source' => 'upload',
                    'url' => trailingslashit($upload_info['baseurl']) . rawurlencode($item),
                ];
            }
        }

        usort($files, function($a, $b) {
            return strcasecmp($a['filename'], $b['filename']);
        });

        return $files;
    }

    private function render_cdn_file_options($selected_value = '') {
        $selected_value = $this->normalize_cdn_replacement_value($selected_value);

        foreach ($this->get_local_cdn_files() as $file) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($file['value']),
                selected($selected_value, $file['value'], false),
                esc_html($file['label'])
            );
        }
    }

    private function is_woocommerce_active() {
        return $this->is_plugin_active_by_files(
            ['woocommerce/woocommerce.php'],
            [class_exists('WooCommerce'), function_exists('WC')]
        );
    }

    private function is_plugin_active_by_files(array $plugin_files, array $fallbacks = []) {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ($plugin_files as $plugin_file) {
            if (is_plugin_active($plugin_file)) {
                return true;
            }
        }

        foreach ($fallbacks as $fallback) {
            if ($fallback) {
                return true;
            }
        }

        return false;
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function setup_hooks() {
        // افزودن منو
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // ثبت تنظیمات
        add_action('admin_init', [$this, 'register_settings']);
        
        // افزودن فایل‌های CSS و JS
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // هندلرهای AJAX
        add_action('wp_ajax_cloudtart_refresh_logs', [$this, 'ajax_refresh_logs']);
        add_action('wp_ajax_cloudtart_cdn_upload_file', [$this, 'ajax_cdn_upload_file']);
        add_action('admin_post_cloudtart_internal_cdn_download_log', [$this, 'download_internal_cdn_log']);
        add_action('admin_post_cloudtart_internal_cdn_delete_log', [$this, 'delete_internal_cdn_log']);
        add_action('admin_post_cloudtart_save_all_settings', [$this, 'save_all_settings']);
        add_action('admin_notices', [$this, 'maybe_show_connector_notice']);
    }

    /**
     * سایت‌هایی که پیش از جداشدن کانکتور به پلتفرم متصل بوده‌اند (کلید API دارند) اما
     * کانکتور روی آن‌ها فعال نیست، بدون این افزونه دیگر آپدیت دریافت نمی‌کنند.
     */
    public function maybe_show_connector_notice() {
        if (!current_user_can('manage_options') || cloudtart_support_connector_active()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['settings_page_cloudtart-support', 'plugins'], true)) {
            return;
        }

        // Result of the "install connector" button.
        $install_result = isset($_GET['cloudtart_connector_install']) ? sanitize_key(wp_unslash($_GET['cloudtart_connector_install'])) : '';
        if ($install_result === 'failed') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('The CloudTart Support Connector could not be installed automatically. Details are in the plugin log; you can also upload the connector zip from Plugins → Add New.', 'cloudtart-support') . '</p></div>';
        }

        $legacy_key = isset($this->settings['api_key']) ? trim((string) $this->settings['api_key']) : '';
        if ($legacy_key === '') {
            return;
        }

        $message = esc_html__('This site has a CloudTart support API key, but the "CloudTart Support Connector" plugin is not active. Remote updates stay paused until the connector is installed and activated.', 'cloudtart-support');
        $button = '';
        if (current_user_can('install_plugins') && current_user_can('activate_plugins')) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=cloudtart_install_connector'), 'cloudtart_install_connector');
            $button = ' <a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Install and activate the connector', 'cloudtart-support') . '</a>';
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('CloudTart Support', 'cloudtart-support') . ':</strong> '
            . $message . $button // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both parts escaped above
            . '</p></div>';
    }

    /**
     * The settings groups rendered inside the unified admin form, mapped to
     * the callback that validates them.
     */
    private function get_settings_groups() {
        $groups = [
            'general'      => ['option' => 'cloudtart_support_settings',      'sanitize' => [$this, 'sanitize_settings']],
            'expiry'       => ['option' => 'cloudtart_expiry_settings',       'sanitize' => [$this->expiry_manager, 'sanitize_expiry_settings']],
            'monitoring'   => ['option' => 'cloudtart_monitoring_settings',   'sanitize' => [$this, 'sanitize_monitoring_settings']],
            'plugins'      => ['option' => 'cloudtart_plugins_settings',      'sanitize' => [$this, 'sanitize_plugins_settings']],
            'internal_cdn' => ['option' => 'cloudtart_internal_cdn_settings', 'sanitize' => [$this, 'sanitize_internal_cdn_settings']],
        ];

        /**
         * Companion plugins register their own settings group here so it is
         * saved by the unified save button. Each entry needs an 'option' name
         * and a callable 'sanitize'.
         */
        $groups = apply_filters('cloudtart_support_settings_groups', $groups);

        $clean = [];
        foreach ((array) $groups as $key => $config) {
            $key = sanitize_key($key);
            if ($key === '' || !is_array($config) || empty($config['option']) || !is_callable($config['sanitize'])) {
                continue;
            }
            $clean[$key] = $config;
        }

        return $clean;
    }

    /**
     * تب‌های صفحه‌ی تنظیمات (شناسه => عنوان). افزونه‌های مکمل می‌توانند تب اضافه کنند.
     */
    private function get_settings_tabs() {
        $tabs = [
            'expiry'       => __('Expiry Dates', 'cloudtart-support'),
            'monitoring'   => __('Monitoring', 'cloudtart-support'),
            'logs'         => __('Logs', 'cloudtart-support'),
            'general'      => __('Support Settings', 'cloudtart-support'),
            'plugins'      => __('Other Plugin Settings', 'cloudtart-support'),
            'internal_cdn' => __('Internal CDN Settings', 'cloudtart-support'),
            'about'        => __('About Us', 'cloudtart-support'),
        ];

        $tabs = apply_filters('cloudtart_support_settings_tabs', $tabs);

        $clean = [];
        foreach ((array) $tabs as $id => $label) {
            $id = sanitize_key($id);
            if ($id !== '' && is_string($label)) {
                $clean[$id] = $label;
            }
        }

        return $clean;
    }

    private function get_valid_tabs() {
        return array_keys($this->get_settings_tabs());
    }

    /**
     * Unified save handler: persists every settings group rendered on the
     * plugin admin page, no matter which tab the visitor was on when they
     * pressed the save button.
     *
     * A group must be saved even when nothing of it reaches $_POST: a tab made
     * only of checkboxes (the Internal CDN tab, for example) sends no fields at
     * all once every box is cleared, and skipping it would silently keep the
     * previous values. The form therefore submits an explicit list of the
     * groups it rendered, and that list — not the presence of field data — is
     * what decides which options get written.
     */
    public function save_all_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'cloudtart-support'), 403);
        }

        check_admin_referer('cloudtart_save_all_settings', 'cloudtart_save_all_nonce');

        $valid_tabs = $this->get_valid_tabs();
        $active_tab = isset($_POST['cloudtart_active_tab'])
            ? sanitize_key(wp_unslash($_POST['cloudtart_active_tab']))
            : 'expiry';
        if (!in_array($active_tab, $valid_tabs, true)) {
            $active_tab = 'expiry';
        }

        $saved_groups = $this->persist_submitted_settings($_POST);

        set_transient(
            'cloudtart_support_save_notice_' . get_current_user_id(),
            ['saved_groups' => $saved_groups],
            60
        );

        $redirect = add_query_arg(
            [
                'page' => 'cloudtart-support',
                'tab' => $active_tab,
                'cloudtart_saved' => '1',
            ],
            admin_url('options-general.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Writes every settings group the submission declared and returns the keys
     * of the groups that were persisted.
     */
    private function persist_submitted_settings(array $request) {
        $submitted_groups = isset($request['cloudtart_settings_groups']) && is_array($request['cloudtart_settings_groups'])
            ? array_map('sanitize_key', wp_unslash($request['cloudtart_settings_groups']))
            : [];

        $saved_groups = [];

        foreach ($this->get_settings_groups() as $group => $config) {
            $posted = isset($request[$config['option']]) && is_array($request[$config['option']])
                ? wp_unslash($request[$config['option']])
                : null;

            if ($posted === null && !in_array($group, $submitted_groups, true)) {
                continue;
            }

            update_option(
                $config['option'],
                call_user_func($config['sanitize'], $posted === null ? [] : $posted)
            );
            $saved_groups[] = $group;
        }

        return $saved_groups;
    }

    /**
     * مدیریت آپلود فایل CDN از طریق AJAX
     */
    public function ajax_cdn_upload_file() {
        check_ajax_referer('cloudtart_support_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'cloudtart-support')], 403);
        }

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => __('No file was uploaded.', 'cloudtart-support')], 400);
        }

        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $allowed_mimes = $this->get_internal_cdn_allowed_upload_mimes();

        $file_name = isset($_FILES['file']['name']) ? wp_unslash($_FILES['file']['name']) : '';
        $file_info = wp_check_filetype(basename($file_name), $allowed_mimes);
        if (empty($file_info['ext'])) {
            wp_send_json_error(['message' => __('Invalid file type.', 'cloudtart-support')], 400);
        }

        $upload_info = $this->get_internal_cdn_upload_dir();
        if (!is_dir($upload_info['basedir'])) {
            wp_mkdir_p($upload_info['basedir']);
        }

        $upload_dir_filter = function($dirs) use ($upload_info) {
            $dirs['path'] = $upload_info['basedir'];
            $dirs['url'] = $upload_info['baseurl'];
            $dirs['basedir'] = $upload_info['basedir'];
            $dirs['baseurl'] = $upload_info['baseurl'];
            return $dirs;
        };

        $mime_filter = function() use ($allowed_mimes) {
            return $allowed_mimes;
        };

        // WordPress performs a second MIME/ext validation during wp_handle_upload().
        // For text-based assets like CSS/JS the detected real MIME is often stricter
        // than the whitelist, so confirm the type from the approved extension list
        // for this dedicated internal CDN upload only.
        $filetype_filter = function($data, $file, $filename) use ($allowed_mimes) {
            $checked = wp_check_filetype($filename, $allowed_mimes);

            if (empty($checked['ext']) || empty($checked['type'])) {
                return $data;
            }

            return [
                'ext' => $checked['ext'],
                'type' => $checked['type'],
                'proper_filename' => isset($data['proper_filename']) ? $data['proper_filename'] : false,
            ];
        };

        add_filter('upload_dir', $upload_dir_filter);
        add_filter('upload_mimes', $mime_filter);
        add_filter('wp_check_filetype_and_ext', $filetype_filter, 10, 3);

        $movefile = wp_handle_upload($_FILES['file'], [
            'test_form' => false,
            'mimes' => $allowed_mimes,
        ]);

        remove_filter('upload_dir', $upload_dir_filter);
        remove_filter('upload_mimes', $mime_filter);
        remove_filter('wp_check_filetype_and_ext', $filetype_filter, 10);

        if ($movefile && !isset($movefile['error'])) {
            $stored_name = basename($movefile['file']);
            wp_send_json_success([
                'message' => __('File uploaded successfully.', 'cloudtart-support'),
                'file' => $stored_name,
                'url' => $movefile['url'],
                'optionValue' => 'upload:' . $stored_name,
                'optionLabel' => $stored_name . ' (' . __('User upload', 'cloudtart-support') . ')',
            ]);
        }

        wp_send_json_error([
            'message' => isset($movefile['error']) ? $movefile['error'] : __('Unknown upload error.', 'cloudtart-support'),
        ], 500);
    }

    /**
     * افزودن منوی تنظیمات
     */
    public function add_admin_menu() {
        add_options_page(
            __('CloudTart Support Settings', 'cloudtart-support'),
            __('CloudTart Support', 'cloudtart-support'),
            'manage_options',
            'cloudtart-support',
            [$this, 'render_settings_page']
        );
    }

    /**
     * ثبت تنظیمات
     */
    public function register_settings() {
        // تنظیمات اصلی
        register_setting(
            'cloudtart_support_settings', 
            'cloudtart_support_settings', 
            [$this, 'sanitize_settings']
        );

        add_settings_section(
            'cloudtart_support_main_section',
            __('Main Settings', 'cloudtart-support'),
            [$this, 'render_main_section'],
            'cloudtart-support'
        );

        add_settings_field(
            'debug',
            __('Debug Mode', 'cloudtart-support'),
            [$this, 'render_debug_field'],
            'cloudtart-support',
            'cloudtart_support_main_section'
        );

        
        // تنظیمات تاریخ انقضا
        register_setting(
            'cloudtart_expiry_settings',
            'cloudtart_expiry_settings',
            [$this->expiry_manager, 'sanitize_expiry_settings']
        );
        
        add_settings_section(
            'cloudtart_expiry_section',
            __('Expiry Settings', 'cloudtart-support'),
            [$this, 'render_expiry_section'],
            'cloudtart-expiry'
        );
        
        add_settings_field(
            'domain_expiry',
            __('Domain Expiry Date', 'cloudtart-support'),
            [$this, 'render_domain_expiry_field'],
            'cloudtart-expiry',
            'cloudtart_expiry_section'
        );
        
        add_settings_field(
            'domain_period',
            __('Domain Renewal Period', 'cloudtart-support'),
            [$this, 'render_domain_period_field'],
            'cloudtart-expiry',
            'cloudtart_expiry_section'
        );
        
        add_settings_field(
            'hosting_expiry',
            __('Hosting Expiry Date', 'cloudtart-support'),
            [$this, 'render_hosting_expiry_field'],
            'cloudtart-expiry',
            'cloudtart_expiry_section'
        );
        
        add_settings_field(
            'hosting_period',
            __('Hosting Renewal Period', 'cloudtart-support'),
            [$this, 'render_hosting_period_field'],
            'cloudtart-expiry',
            'cloudtart_expiry_section'
        );
        
        add_settings_field(
            'notification_emails',
            __('Notification Emails', 'cloudtart-support'),
            [$this, 'render_notification_emails_field'],
            'cloudtart-expiry',
            'cloudtart_expiry_section'
        );
        
        add_settings_field(
            'notification_days',
            __('Days Before Expiry for Notification', 'cloudtart-support'),
            [$this, 'render_notification_days_field'],
            'cloudtart-expiry',
            'cloudtart_expiry_section'
        );
        
        // تنظیمات مانیتورینگ
        register_setting(
            'cloudtart_monitoring_settings',
            'cloudtart_monitoring_settings',
            [$this, 'sanitize_monitoring_settings']
        );
        
        add_settings_section(
            'cloudtart_monitoring_section',
            __('Site Monitoring Settings', 'cloudtart-support'),
            [$this, 'render_monitoring_section'],
            'cloudtart-monitoring'
        );
        
        add_settings_field(
            'status_monitoring',
            __('Site Status Monitoring', 'cloudtart-support'),
            [$this, 'render_status_monitoring_field'],
            'cloudtart-monitoring',
            'cloudtart_monitoring_section'
        );
        
        add_settings_field(
            'error_monitoring',
            __('PHP Error Monitoring', 'cloudtart-support'),
            [$this, 'render_error_monitoring_field'],
            'cloudtart-monitoring',
            'cloudtart_monitoring_section'
        );
        
        add_settings_field(
            'notification_email',
            __('Notification Email', 'cloudtart-support'),
            [$this, 'render_notification_email_field'],
            'cloudtart-monitoring',
            'cloudtart_monitoring_section'
        );
        
        add_settings_field(
            'check_interval',
            __('Check Interval', 'cloudtart-support'),
            [$this, 'render_check_interval_field'],
            'cloudtart-monitoring',
            'cloudtart_monitoring_section'
        );
        
        // تنظیمات پلاگین‌ها
        register_setting(
            'cloudtart_plugins_settings',
            'cloudtart_plugins_settings',
            [$this, 'sanitize_plugins_settings']
        );
        
        add_settings_section(
            'cloudtart_plugins_section',
            __('Manage Add-on Plugins', 'cloudtart-support'),
            [$this, 'render_plugins_section'],
            'cloudtart-plugins'
        );
        
        add_settings_field(
            'enable_digits_iranpayamak',
            __('Digits plugin — IranPayamak SMS gateway', 'cloudtart-support'),
            [$this, 'render_enable_digits_iranpayamak_field'],
            'cloudtart-plugins',
            'cloudtart_plugins_section'
        );

        add_settings_field(
            'enable_dokan_fix',
            __('Dokan Multivendor plugin — coupon fix', 'cloudtart-support'),
            [$this, 'render_enable_dokan_fix_field'],
            'cloudtart-plugins',
            'cloudtart_plugins_section'
        );

        add_settings_field(
            'enable_wallet_withdraw_orders',
            __('FS WooCommerce Wallet plugin — withdrawals as orders', 'cloudtart-support'),
            [$this, 'render_enable_wallet_withdraw_orders_field'],
            'cloudtart-plugins',
            'cloudtart_plugins_section'
        );

        add_settings_field(
            'enable_pwsms_iranpayamak',
            __('Persian WooCommerce SMS plugin — IranPayamak gateway', 'cloudtart-support'),
            [$this, 'render_enable_pwsms_iranpayamak_field'],
            'cloudtart-plugins',
            'cloudtart_plugins_section'
        );

        add_settings_field(
            'enable_wc_audio_stream',
            __('WooCommerce — stream purchased audio files', 'cloudtart-support'),
            [$this, 'render_enable_wc_audio_stream_field'],
            'cloudtart-plugins',
            'cloudtart_plugins_section'
        );

        add_settings_field(
            'wc_audio_stream_options',
            __('Audio streaming options', 'cloudtart-support'),
            [$this, 'render_wc_audio_stream_options_field'],
            'cloudtart-plugins',
            'cloudtart_plugins_section'
        );

        // تنظیمات اینترنت داخلی
        register_setting(
            'cloudtart_internal_cdn_settings',
            'cloudtart_internal_cdn_settings',
            [$this, 'sanitize_internal_cdn_settings']
        );

        add_settings_section(
            'cloudtart_internal_cdn_section',
            __('Internal CDN Settings', 'cloudtart-support'),
            [$this, 'render_internal_cdn_section'],
            'cloudtart-internal-cdn'
        );

        add_settings_field(
            'cdn_enabled',
            __('File redirects', 'cloudtart-support'),
            [$this, 'render_cdn_enabled_field'],
            'cloudtart-internal-cdn',
            'cloudtart_internal_cdn_section'
        );

        add_settings_field(
            'cdn_replacements',
            __('Replacement rules', 'cloudtart-support'),
            [$this, 'render_cdn_replacements_field'],
            'cloudtart-internal-cdn',
            'cloudtart_internal_cdn_section'
        );

        add_settings_field(
            'cdn_block_external_requests',
            __('Block external requests', 'cloudtart-support'),
            [$this, 'render_cdn_block_external_requests_field'],
            'cloudtart-internal-cdn',
            'cloudtart_internal_cdn_section'
        );

        add_settings_field(
            'cdn_block_external_http',
            __('Intranet mode (server requests)', 'cloudtart-support'),
            [$this, 'render_cdn_block_external_http_field'],
            'cloudtart-internal-cdn',
            'cloudtart_internal_cdn_section'
        );

        add_settings_field(
            'cdn_allow_ir_domains',
            __('Iranian domains', 'cloudtart-support'),
            [$this, 'render_cdn_allow_ir_domains_field'],
            'cloudtart-internal-cdn',
            'cloudtart_internal_cdn_section'
        );

        add_settings_field(
            'cdn_allowed_domains',
            __('Allowed domains', 'cloudtart-support'),
            [$this, 'render_cdn_allowed_domains_field'],
            'cloudtart-internal-cdn',
            'cloudtart_internal_cdn_section'
        );

        add_settings_field(
            'cdn_allowed_keywords',
            __('Allowed keywords', 'cloudtart-support'),
            [$this, 'render_cdn_allowed_keywords_field'],
            'cloudtart-internal-cdn',
            'cloudtart_internal_cdn_section'
        );

        add_settings_field(
            'cdn_blocked_domains_enabled',
            __('Block domains', 'cloudtart-support'),
            [$this, 'render_cdn_block_domains_enabled_field'],
            'cloudtart-internal-cdn',
            'cloudtart_internal_cdn_section'
        );

        add_settings_field(
            'cdn_blocked_domains',
            __('Blocked domains', 'cloudtart-support'),
            [$this, 'render_cdn_blocked_domains_field'],
            'cloudtart-internal-cdn',
            'cloudtart_internal_cdn_section'
        );

        add_settings_field(
            'cdn_log_actions',
            __('Log file management', 'cloudtart-support'),
            [$this, 'render_cdn_log_actions_field'],
            'cloudtart-internal-cdn',
            'cloudtart_internal_cdn_section'
        );
    }

    /**
     * اعتبارسنجی تنظیمات اصلی
     */
    public function sanitize_settings($settings) {
        $settings = is_array($settings) ? $settings : [];

        // Only the debug flag is owned by this tab. Anything else stored in the
        // option (for example the API key kept from older versions until the
        // connector adopts it) is preserved untouched.
        $settings = wp_parse_args($settings, is_array($this->settings) ? $this->settings : []);
        $settings['debug'] = isset($settings['debug']) && $settings['debug'] == '1' ? '1' : '0';

        if ($this->logger) {
            $this->logger->set_debug_mode($settings['debug'] == '1');
            $this->logger->log(sprintf(
                __('Main settings saved. Debug mode: %s', 'cloudtart-support'),
                $settings['debug'] == '1' ? __('Enabled', 'cloudtart-support') : __('Disabled', 'cloudtart-support')
            ));
        }

        return $settings;
    }

    /**
     * اعتبارسنجی تنظیمات مانیتورینگ
     */
    public function sanitize_monitoring_settings($settings) {
        if ($this->logger) {
            $this->logger->log(__('Started validating monitoring settings', 'cloudtart-support'));
        }

        $settings = wp_parse_args(is_array($settings) ? $settings : [], [
            'status_monitoring' => '0',
            'error_monitoring' => '0',
            'notification_email' => get_option('admin_email'),
            'check_interval' => 'hourly',
        ]);

        // فعال/غیرفعال کردن مانیتورینگ وضعیت
        $settings['status_monitoring'] = isset($settings['status_monitoring']) && $settings['status_monitoring'] === '1' ? '1' : '0';
        
        // فعال/غیرفعال کردن مانیتورینگ خطاها
        $settings['error_monitoring'] = isset($settings['error_monitoring']) && $settings['error_monitoring'] === '1' ? '1' : '0';
        
        // بررسی ایمیل اطلاع‌رسانی
        if (!empty($settings['notification_email'])) {
            if (!is_email($settings['notification_email'])) {
                $settings['notification_email'] = get_option('admin_email');
            }
        }
        
        // بررسی فاصله زمانی
        $valid_intervals = ['hourly', 'twicedaily', 'daily'];
        if (!in_array($settings['check_interval'], $valid_intervals)) {
            $settings['check_interval'] = 'hourly';
        }
        
        // تنظیم کرون‌جاب مانیتورینگ
        if ($settings['status_monitoring'] == '1' || $settings['error_monitoring'] == '1') {
            // حذف زمانبندی قبلی
            wp_clear_scheduled_hook('cloudtart_check_status');
            
            // اضافه کردن زمانبندی جدید اگر هنوز تنظیم نشده باشد
            if (!wp_next_scheduled('cloudtart_check_status')) {
                wp_schedule_event(time(), $settings['check_interval'], 'cloudtart_check_status');
            }
        } else {
            // حذف زمانبندی
            wp_clear_scheduled_hook('cloudtart_check_status');
        }
        
        if ($this->logger) {
            $this->logger->log(__('Monitoring settings validated.', 'cloudtart-support'));
        }
        
        return $settings;
    }

    /**
     * اعتبارسنجی تنظیمات پلاگین‌ها
     */
    public function sanitize_plugins_settings($settings) {
        if ($this->logger) {
            $this->logger->log(__('Started validating plugin settings', 'cloudtart-support'));
        }

        $settings = is_array($settings) ? $settings : [];

        // مهاجرت سازگار با ورودی قدیمی
        if (!isset($settings['enable_digits_iranpayamak']) && isset($settings['enable_digits_ippanel'])) {
            $settings['enable_digits_iranpayamak'] = $settings['enable_digits_ippanel'];
        }

        // فعال/غیرفعال کردن درگاه ایران پیامک برای Digits
        $settings['enable_digits_iranpayamak'] = isset($settings['enable_digits_iranpayamak']) && $settings['enable_digits_iranpayamak'] === '1' ? '1' : '0';
        
        // فعال/غیرفعال کردن فیکس Dokan
        $settings['enable_dokan_fix'] = isset($settings['enable_dokan_fix']) && $settings['enable_dokan_fix'] === '1' ? '1' : '0';
        
        // فعال/غیرفعال کردن ماژول مدیریت برداشت کیف پول با سفارشات ووکامرس
        $is_wallet_active = $this->is_plugin_active_by_files(
            ['FS_WooCommerce_Wallet/main.php', 'fs-woocommerce-wallet/main.php', 'woo-wallet/main.php'],
            [class_exists('FS_WC_Wallet'), class_exists('Wallet')]
        );
        $settings['enable_wallet_withdraw_orders'] = (
            $is_wallet_active &&
            isset($settings['enable_wallet_withdraw_orders']) &&
            $settings['enable_wallet_withdraw_orders'] === '1'
        ) ? '1' : '0';

        // فعال/غیرفعال کردن درگاه ایران پیامک برای پلاگین پیامک ووکامرس فارسی
        $is_pwsms_active = $this->is_plugin_active_by_files(
            ['persian-woocommerce-sms/WoocommerceIR_SMS.php'],
            [defined('PWSMS_VERSION'), function_exists('PWSMS')]
        );
        $settings['enable_pwsms_iranpayamak'] = (
            $is_pwsms_active &&
            isset($settings['enable_pwsms_iranpayamak']) &&
            $settings['enable_pwsms_iranpayamak'] === '1'
        ) ? '1' : '0';

        // فعال/غیرفعال کردن پخش آنلاین فایل‌های صوتی ووکامرس
        // The sub-options are kept even while the feature is off, so re-enabling it
        // restores the admin's previous choices.
        $settings['enable_wc_audio_stream'] = (
            $this->is_woocommerce_active() &&
            isset($settings['enable_wc_audio_stream']) &&
            $settings['enable_wc_audio_stream'] === '1'
        ) ? '1' : '0';
        $settings['wc_audio_allow_download'] = isset($settings['wc_audio_allow_download']) && $settings['wc_audio_allow_download'] === '1' ? '1' : '0';
        $settings['wc_audio_show_player'] = isset($settings['wc_audio_show_player']) && $settings['wc_audio_show_player'] === '0' ? '0' : '1';
        $settings['wc_audio_player_theme'] = (isset($settings['wc_audio_player_theme']) && $settings['wc_audio_player_theme'] === 'light') ? 'light' : 'dark';

        if ($this->logger) {
            $this->logger->log(sprintf(
                __('Plugin settings validated. Digits IranPayamak gateway: %1$s, Dokan coupon fix: %2$s, Wallet withdraw orders: %3$s, Persian WooCommerce SMS IranPayamak: %4$s, WooCommerce audio streaming: %5$s', 'cloudtart-support'),
                $settings['enable_digits_iranpayamak'],
                $settings['enable_dokan_fix'],
                $settings['enable_wallet_withdraw_orders'],
                $settings['enable_pwsms_iranpayamak'],
                $settings['enable_wc_audio_stream']
            ));
        }
        
        return $settings;
    }

    /**
     * اعتبارسنجی تنظیمات اینترنت داخلی
     */
    public function sanitize_internal_cdn_settings($settings) {
        if ($this->logger) {
            $this->logger->log(__('Started validating internal CDN settings', 'cloudtart-support'));
        }

        $settings = is_array($settings) ? $settings : [];
        $defaults = $this->get_default_internal_cdn_settings();
        $existing = is_array($this->internal_cdn_settings) ? $this->internal_cdn_settings : [];

        // Each list wrapper in the form carries a "_present" marker so an empty
        // list the admin deliberately cleared can be told apart from a list the
        // submission never contained (a programmatic update_option() call, for
        // instance). Markers are form plumbing and must not reach the option.
        $present = isset($settings['_present']) && is_array($settings['_present']) ? $settings['_present'] : [];
        unset($settings['_present']);

        // Every toggle ships a hidden "0" companion, so an absent key here means
        // the group was not rendered at all rather than "the box is unchecked".
        foreach (['enabled', 'block_external_requests', 'block_domains', 'block_external_http', 'allow_ir_domains', 'log_blocked_requests'] as $flag) {
            $settings[$flag] = isset($settings[$flag]) && $settings[$flag] === '1' ? '1' : '0';
        }

        foreach (['replacements', 'blocked_domains', 'allowed_domains', 'allowed_keywords'] as $key) {
            $submitted = !empty($present[$key]) || array_key_exists($key, $settings);

            if (!$submitted) {
                $settings[$key] = array_key_exists($key, $existing) && is_array($existing[$key])
                    ? $existing[$key]
                    : (array) $defaults[$key];
                continue;
            }

            $raw = (isset($settings[$key]) && is_array($settings[$key])) ? $settings[$key] : [];
            $settings[$key] = $key === 'replacements'
                ? $this->sanitize_cdn_replacements($raw)
                : $this->sanitize_cdn_text_list($raw);

            // دامنه‌ها به شکل «فقط نام میزبان» ذخیره می‌شوند (https:// و مسیر حذف می‌شود).
            if ($key === 'blocked_domains' || $key === 'allowed_domains') {
                $settings[$key] = array_values(array_unique(array_filter(array_map(['CloudTart_Internal_CDN', 'clean_domain'], $settings[$key]))));
            } elseif ($key === 'allowed_keywords') {
                $settings[$key] = array_values(array_unique(array_filter(array_map('strtolower', $settings[$key]))));
            }
        }

        if ($this->logger) {
            $this->logger->log(__('Internal CDN settings validated.', 'cloudtart-support'));
        }

        return $settings;
    }

    private function sanitize_cdn_replacements(array $rules) {
        $sanitized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $original = isset($rule['original']) ? esc_url_raw(trim((string) $rule['original'])) : '';
            $replace  = isset($rule['replace']) ? $this->normalize_cdn_replacement_value($rule['replace']) : '';

            if ($original === '' || $replace === '') {
                continue;
            }

            $sanitized[] = [
                'original' => $original,
                'replace' => $replace,
            ];
        }

        return $sanitized;
    }

    private function sanitize_cdn_text_list(array $items) {
        $values = [];

        foreach ($items as $item) {
            $item = sanitize_text_field(trim((string) $item));
            if ($item !== '') {
                $values[] = $item;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * افزودن فایل‌های CSS و JS
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_cloudtart-support') {
            return;
        }

        wp_enqueue_style(
            'cloudtart-support-admin',
            CLOUDTART_SUPPORT_URL . 'assets/css/admin.css',
            [],
            CLOUDTART_SUPPORT_VERSION
        );

        wp_enqueue_script(
            'cloudtart-support-admin',
            CLOUDTART_SUPPORT_URL . 'assets/js/admin.js',
            ['jquery'],
            CLOUDTART_SUPPORT_VERSION,
            true
        );

        wp_localize_script('cloudtart-support-admin', 'cloudtartSupport', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cloudtart_support_nonce'),
            'defaultTab' => 'expiry',
            'i18n' => [
                'testing' => esc_html__('Testing...', 'cloudtart-support'),
                'testingConnection' => esc_html__('Testing connection...', 'cloudtart-support'),
                'testConnection' => esc_html__('Test Server Connection', 'cloudtart-support'),
                'connectionError' => esc_html__('Server connection error.', 'cloudtart-support'),
                'loading' => esc_html__('Loading...', 'cloudtart-support'),
                'refreshLogs' => esc_html__('Refresh Logs', 'cloudtart-support'),
                'logsFetchError' => esc_html__('Error fetching logs: ', 'cloudtart-support'),
                'serverVersionLabel' => esc_html__('Server Version:', 'cloudtart-support'),
                'serverStatusLabel' => esc_html__('Server Status:', 'cloudtart-support'),
                'domainRequired' => esc_html__('Please enter the domain name and expiry date.', 'cloudtart-support'),
                'addingDomain' => esc_html__('Adding domain...', 'cloudtart-support'),
                'deleteDomainConfirm' => esc_html__('Are you sure you want to delete this domain?', 'cloudtart-support'),
                'noDomains' => esc_html__('No domains have been added.', 'cloudtart-support'),
                'remove' => esc_html__('Remove', 'cloudtart-support'),
                'uploading' => esc_html__('Uploading...', 'cloudtart-support'),
                'upload' => esc_html__('Upload', 'cloudtart-support'),
                'uploadSelectFile' => esc_html__('Please choose a file first.', 'cloudtart-support'),
                'uploadSuccess' => esc_html__('File uploaded successfully.', 'cloudtart-support'),
                'uploadFailed' => esc_html__('File upload failed.', 'cloudtart-support'),
                'addRule' => esc_html__('Add Rule', 'cloudtart-support'),
                'addDomainLabel' => esc_html__('Add Domain', 'cloudtart-support'),
                'addKeyword' => esc_html__('Add Keyword', 'cloudtart-support'),
            ]
        ]);
    }

    /**
     * نمایش توضیحات بخش اصلی
     */
    public function render_main_section() {
        echo '<p>' . esc_html__('Enter CloudTart support settings here.', 'cloudtart-support') . '</p>';
    }
    
    /**
     * نمایش توضیحات بخش تاریخ انقضا
     */
    public function render_expiry_section() {
        echo '<p>' . esc_html__('Enter domain and hosting expiry dates so the system can notify you before expiry.', 'cloudtart-support') . '</p>';
    }
    
    /**
     * نمایش توضیحات بخش مانیتورینگ
     */
    public function render_monitoring_section() {
        echo '<p>' . esc_html__('Configure site status and PHP error monitoring here.', 'cloudtart-support') . '</p>';
    }

    /**
     * نمایش توضیحات بخش پلاگین‌ها
     */
    public function render_plugins_section() {
        echo '<p>' . esc_html__('Enable or disable add-on plugins and extra features here.', 'cloudtart-support') . '</p>';
    }

    /**
     * نمایش توضیحات بخش اینترنت داخلی
     */
    public function render_internal_cdn_section() {
        $log_action = isset($_GET['cloudtart_cdn_log']) ? sanitize_key(wp_unslash($_GET['cloudtart_cdn_log'])) : '';
        echo '<p>' . esc_html__('In this section you can manage redirects from external CDN files to local files, block external requests, manage allowed domains and allowed keywords, and block specific domains.', 'cloudtart-support') . '</p>';
        if ($log_action === 'deleted') {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('The blocked requests log file has been deleted.', 'cloudtart-support') . '</p></div>';
        } elseif ($log_action === 'missing') {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('The log file was not found for download or deletion.', 'cloudtart-support') . '</p></div>';
        } elseif ($log_action === 'delete_failed') {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Could not delete the log file. Please check the file permissions or the path.', 'cloudtart-support') . '</p></div>';
        }
    }

    /**
     * نمایش فیلد فعال‌سازی CDN داخلی
     */
    public function render_cdn_enabled_field() {
        $enabled = isset($this->internal_cdn_settings['enabled']) && $this->internal_cdn_settings['enabled'] == '1';
        ?>
        <label class="ct-check">
            <input type="hidden" name="cloudtart_internal_cdn_settings[enabled]" value="0">
            <input type="checkbox" name="cloudtart_internal_cdn_settings[enabled]" value="1" <?php checked($enabled); ?>>
            <span><?php echo esc_html__('Enable file redirects (JS/CSS and static assets)', 'cloudtart-support'); ?></span>
        </label>
        <p class="description"><?php echo esc_html__('When enabled, replacement rules are activated and the defined assets are redirected to the target files.', 'cloudtart-support'); ?></p>
        <?php
    }

    public function render_cdn_log_actions_field() {
        $log_file_info = $this->get_internal_cdn_log_file_info();
        $log_file = $log_file_info['file_path'];
        $has_log = is_file($log_file);
        $log_enabled = isset($this->internal_cdn_settings['log_blocked_requests']) && $this->internal_cdn_settings['log_blocked_requests'] === '1';
        $download_url = wp_nonce_url(
            admin_url('admin-post.php?action=cloudtart_internal_cdn_download_log'),
            'cloudtart_internal_cdn_download_log'
        );
        $delete_url = wp_nonce_url(
            admin_url('admin-post.php?action=cloudtart_internal_cdn_delete_log'),
            'cloudtart_internal_cdn_delete_log'
        );
        ?>
        <div class="cdn-log-actions-wrapper">
            <label class="ct-check">
                <input type="hidden" name="cloudtart_internal_cdn_settings[log_blocked_requests]" value="0">
                <input type="checkbox" name="cloudtart_internal_cdn_settings[log_blocked_requests]" value="1" <?php checked($log_enabled); ?>>
                <span><?php echo esc_html__('Enable request logging', 'cloudtart-support'); ?></span>
            </label>
            <p class="description"><?php echo esc_html__('When enabled, blocked URLs and URLs redirected to local files are recorded in the log file.', 'cloudtart-support'); ?></p>
            <p class="description"><?php echo esc_html(sprintf(__('Log file path: %s', 'cloudtart-support'), $log_file)); ?></p>
            <?php if ($has_log): ?>
                <p class="description">
                    <?php
                    echo esc_html(sprintf(
                        __('Last modified: %1$s | File size: %2$s', 'cloudtart-support'),
                        wp_date('Y-m-d H:i:s', filemtime($log_file)),
                        size_format((float) filesize($log_file), 2)
                    ));
                    ?>
                </p>
                <p>
                    <a href="<?php echo esc_url($download_url); ?>" class="button button-secondary"><?php echo esc_html__('Download log file', 'cloudtart-support'); ?></a>
                    <a href="<?php echo esc_url($delete_url); ?>" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete the log file?', 'cloudtart-support')); ?>');"><?php echo esc_html__('Delete log file', 'cloudtart-support'); ?></a>
                </p>
            <?php else: ?>
                <p class="description"><?php echo esc_html__('No log file has been created yet. Enable logging first; once the first blocked or redirected request is recorded, the file will be available here to download or delete.', 'cloudtart-support'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * نمایش فیلد قوانین جایگزینی CDN
     */
    public function render_cdn_replacements_field() {
        $redirect_enabled = isset($this->internal_cdn_settings['enabled']) && $this->internal_cdn_settings['enabled'] === '1';
        $replacements = isset($this->internal_cdn_settings['replacements']) ? $this->internal_cdn_settings['replacements'] : [];
        $upload_info = $this->get_internal_cdn_upload_dir();
        if (empty($replacements)) {
            $default_settings = $this->get_default_internal_cdn_settings();
            $replacements = isset($default_settings['replacements']) ? (array) $default_settings['replacements'] : [];
        }
        ?>
        <p id="cdn-replacements-hint" class="description" <?php echo $redirect_enabled ? 'style="display:none"' : ''; ?>>
            <?php echo esc_html__('To enable this section, first turn on the "Enable file redirects" option.', 'cloudtart-support'); ?>
        </p>
        <div id="cdn-replacements-wrapper" <?php echo $redirect_enabled ? '' : 'style="display:none"'; ?>>
            <input type="hidden" name="cloudtart_internal_cdn_settings[_present][replacements]" value="1">
            <table class="wp-list-table widefat fixed striped" id="cdn-replacements-table">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column"><?php echo esc_html__('Original URL', 'cloudtart-support'); ?></th>
                        <th scope="col" class="manage-column"><?php echo esc_html__('Target file', 'cloudtart-support'); ?></th>
                        <th scope="col" class="manage-column"><?php echo esc_html__('Actions', 'cloudtart-support'); ?></th>
                    </tr>
                </thead>
                <tbody id="the-list">
                    <?php if (!empty($replacements)): ?>
                        <?php foreach ($replacements as $index => $rule): ?>
                            <tr data-index="<?php echo $index; ?>">
                                <td><input type="text" name="cloudtart_internal_cdn_settings[replacements][<?php echo $index; ?>][original]" value="<?php echo esc_attr($rule['original']); ?>" class="large-text"></td>
                                <td>
                                    <select name="cloudtart_internal_cdn_settings[replacements][<?php echo $index; ?>][replace]">
                                        <?php $this->render_cdn_file_options(isset($rule['replace']) ? $rule['replace'] : ''); ?>
                                    </select>
                                </td>
                                <td><button type="button" class="button button-secondary remove-rule"><?php echo esc_html__('Remove', 'cloudtart-support'); ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot style="display: none;">
                    <tr id="replacement-rule-template">
                        <td><input type="text" name="" value="" class="large-text"></td>
                        <td>
                            <select name="">
                                <?php $this->render_cdn_file_options(); ?>
                            </select>
                        </td>
                        <td><button type="button" class="button button-secondary remove-rule"><?php echo esc_html__('Remove', 'cloudtart-support'); ?></button></td>
                    </tr>
                </tfoot>
            </table>
            <div class="cdn-upload-wrapper" style="margin-top: 12px;">
                <h3><?php echo esc_html__('Upload a new file', 'cloudtart-support'); ?></h3>
                <input type="file" id="cdn-file-upload" name="cdn_file_upload">
                <button type="button" class="button button-primary" id="cdn-upload-button"><?php echo esc_html__('Upload', 'cloudtart-support'); ?></button>
                <p class="description"><?php echo esc_html(sprintf(__('New files are stored at %s so they are not removed when the plugin updates.', 'cloudtart-support'), $upload_info['basedir'])); ?></p>
                <p class="description"><?php echo esc_html__('Allowed formats: JS, CSS, HTML, JSON, WOFF, WOFF2, TTF, EOT, SVG, PNG, JPG, GIF, MP4. After uploading, pick the file from the list below and assign it to the desired URL.', 'cloudtart-support'); ?></p>
                <div id="cdn-upload-status"></div>
            </div>

            <button type="button" class="button button-primary" id="add-replacement-rule" style="margin-top: 10px;"><?php echo esc_html__('Add Rule', 'cloudtart-support'); ?></button>
            <p class="description"><?php echo esc_html__('Connect each external URL to one of the plugin\'s bundled default files or to a file you uploaded yourself.', 'cloudtart-support'); ?></p>
        </div>
        <?php
    }

    /**
     * نمایش فیلد دامنه‌های مسدود شده CDN
     */
    public function render_cdn_block_external_requests_field() {
        $enabled = isset($this->internal_cdn_settings['block_external_requests']) && $this->internal_cdn_settings['block_external_requests'] === '1';
        ?>
        <label class="ct-check">
            <input type="hidden" name="cloudtart_internal_cdn_settings[block_external_requests]" value="0">
            <input type="checkbox" name="cloudtart_internal_cdn_settings[block_external_requests]" value="1" <?php checked($enabled); ?>>
            <span><?php echo esc_html__('Block undefined external requests', 'cloudtart-support'); ?></span>
        </label>
        <p class="description"><?php echo esc_html__('When enabled, if a URL has no replacement rule and is not in the allowed domains/keywords list, its request is blocked.', 'cloudtart-support'); ?></p>
        <?php
    }

    public function render_cdn_block_external_http_field() {
        $enabled = isset($this->internal_cdn_settings['block_external_http']) && $this->internal_cdn_settings['block_external_http'] === '1';
        ?>
        <label class="ct-check">
            <input type="hidden" name="cloudtart_internal_cdn_settings[block_external_http]" value="0">
            <input type="checkbox" name="cloudtart_internal_cdn_settings[block_external_http]" value="1" <?php checked($enabled); ?>>
            <span><?php echo esc_html__('Block every server request to foreign hosts except the allowed list', 'cloudtart-support'); ?></span>
        </label>
        <p class="description"><?php echo esc_html__('Use only while international internet is cut. Update checks of WordPress, plugins and themes, license checks and other API calls to foreign servers are answered instantly with an empty response instead of waiting for a timeout. The site itself, local/private addresses, allowed domains and keywords, Iranian domains (if enabled below) and CloudTart services keep working.', 'cloudtart-support'); ?></p>
        <?php
    }

    public function render_cdn_allow_ir_domains_field() {
        $enabled = !isset($this->internal_cdn_settings['allow_ir_domains']) || $this->internal_cdn_settings['allow_ir_domains'] === '1';
        ?>
        <label class="ct-check">
            <input type="hidden" name="cloudtart_internal_cdn_settings[allow_ir_domains]" value="0">
            <input type="checkbox" name="cloudtart_internal_cdn_settings[allow_ir_domains]" value="1" <?php checked($enabled); ?>>
            <span><?php echo esc_html__('Always allow .ir domains', 'cloudtart-support'); ?></span>
        </label>
        <p class="description"><?php echo esc_html__('Domestic domains stay reachable on the national network, so payment gateways, SMS panels and other Iranian services are not blocked.', 'cloudtart-support'); ?></p>
        <?php
    }

    public function render_cdn_block_domains_enabled_field() {
        $enabled = isset($this->internal_cdn_settings['block_domains']) && $this->internal_cdn_settings['block_domains'] === '1';
        ?>
        <label class="ct-check">
            <input type="hidden" name="cloudtart_internal_cdn_settings[block_domains]" value="0">
            <input type="checkbox" name="cloudtart_internal_cdn_settings[block_domains]" value="1" <?php checked($enabled); ?>>
            <span><?php echo esc_html__('Enable domain blocking (for HTTP requests)', 'cloudtart-support'); ?></span>
        </label>
        <p class="description"><?php echo esc_html__('When enabled, HTTP requests to the domains in the blocked list are short-circuited with an empty response.', 'cloudtart-support'); ?></p>
        <?php
    }

    public function render_cdn_blocked_domains_field() {
        $enabled = isset($this->internal_cdn_settings['block_domains']) && $this->internal_cdn_settings['block_domains'] === '1';
        $blocked_domains = isset($this->internal_cdn_settings['blocked_domains']) ? $this->internal_cdn_settings['blocked_domains'] : [];
        ?>
        <p id="cdn-blocked-domains-hint" class="description" <?php echo $enabled ? 'style="display:none"' : ''; ?>>
            <?php echo esc_html__('To enable the blocked domains list, first turn on the "Enable domain blocking" option.', 'cloudtart-support'); ?>
        </p>
        <div id="cdn-blocked-domains-wrapper" <?php echo $enabled ? '' : 'style="display:none"'; ?>>
            <input type="hidden" name="cloudtart_internal_cdn_settings[_present][blocked_domains]" value="1">
            <table class="wp-list-table widefat fixed striped" id="cdn-blocked-domains-table">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column"><?php echo esc_html__('Domain', 'cloudtart-support'); ?></th>
                        <th scope="col" class="manage-column"><?php echo esc_html__('Actions', 'cloudtart-support'); ?></th>
                    </tr>
                </thead>
                <tbody id="the-list-blocked">
                    <?php if (!empty($blocked_domains)): ?>
                        <?php foreach ($blocked_domains as $index => $domain): ?>
                            <tr data-index="<?php echo $index; ?>">
                                <td><input type="text" name="cloudtart_internal_cdn_settings[blocked_domains][<?php echo $index; ?>]" value="<?php echo esc_attr($domain); ?>" class="large-text"></td>
                                <td><button type="button" class="button button-secondary remove-blocked-domain"><?php echo esc_html__('Remove', 'cloudtart-support'); ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot style="display: none;">
                    <tr id="blocked-domain-template">
                        <td><input type="text" name="" value="" class="large-text"></td>
                        <td><button type="button" class="button button-secondary remove-blocked-domain"><?php echo esc_html__('Remove', 'cloudtart-support'); ?></button></td>
                    </tr>
                </tfoot>
            </table>
            <button type="button" class="button button-primary" id="add-blocked-domain" style="margin-top: 10px;"><?php echo esc_html__('Add Domain', 'cloudtart-support'); ?></button>
            <p class="description"><?php echo esc_html__('HTTP requests to these domains are short-circuited with an empty response.', 'cloudtart-support'); ?></p>
        </div>
        <?php
    }

    public function render_cdn_allowed_domains_field() {
        $enabled = isset($this->internal_cdn_settings['block_external_requests']) && $this->internal_cdn_settings['block_external_requests'] === '1';
        $allowed_domains = isset($this->internal_cdn_settings['allowed_domains']) ? $this->internal_cdn_settings['allowed_domains'] : [];
        ?>
        <p id="cdn-allowed-domains-hint" class="description" <?php echo $enabled ? 'style="display:none"' : ''; ?>>
            <?php echo esc_html__('This list is used when "Block undefined external requests" or intranet mode is enabled.', 'cloudtart-support'); ?>
        </p>
        <div id="cdn-allowed-domains-wrapper" <?php echo $enabled ? '' : 'style="display:none"'; ?>>
            <input type="hidden" name="cloudtart_internal_cdn_settings[_present][allowed_domains]" value="1">
            <table class="wp-list-table widefat fixed striped" id="cdn-allowed-domains-table">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column"><?php echo esc_html__('Allowed domain', 'cloudtart-support'); ?></th>
                        <th scope="col" class="manage-column"><?php echo esc_html__('Actions', 'cloudtart-support'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allowed_domains as $index => $domain): ?>
                        <tr data-index="<?php echo esc_attr($index); ?>">
                            <td><input type="text" name="cloudtart_internal_cdn_settings[allowed_domains][<?php echo esc_attr($index); ?>]" value="<?php echo esc_attr($domain); ?>" class="large-text"></td>
                            <td><button type="button" class="button button-secondary remove-allowed-domain"><?php echo esc_html__('Remove', 'cloudtart-support'); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="display:none;">
                    <tr id="allowed-domain-template">
                        <td><input type="text" name="" value="" class="large-text"></td>
                        <td><button type="button" class="button button-secondary remove-allowed-domain"><?php echo esc_html__('Remove', 'cloudtart-support'); ?></button></td>
                    </tr>
                </tfoot>
            </table>
            <button type="button" class="button button-primary" id="add-allowed-domain" style="margin-top: 10px;"><?php echo esc_html__('Add Domain', 'cloudtart-support'); ?></button>
            <p class="description"><?php echo esc_html__('If a domain is in this list, its static assets are allowed through without being blocked.', 'cloudtart-support'); ?></p>
        </div>
        <?php
    }

    public function render_cdn_allowed_keywords_field() {
        $enabled = isset($this->internal_cdn_settings['block_external_requests']) && $this->internal_cdn_settings['block_external_requests'] === '1';
        $allowed_keywords = isset($this->internal_cdn_settings['allowed_keywords']) ? $this->internal_cdn_settings['allowed_keywords'] : [];
        ?>
        <p id="cdn-allowed-keywords-hint" class="description" <?php echo $enabled ? 'style="display:none"' : ''; ?>>
            <?php echo esc_html__('This list is used when "Block undefined external requests" or intranet mode is enabled.', 'cloudtart-support'); ?>
        </p>
        <div id="cdn-allowed-keywords-wrapper" <?php echo $enabled ? '' : 'style="display:none"'; ?>>
            <input type="hidden" name="cloudtart_internal_cdn_settings[_present][allowed_keywords]" value="1">
            <table class="wp-list-table widefat fixed striped" id="cdn-allowed-keywords-table">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column"><?php echo esc_html__('Keyword', 'cloudtart-support'); ?></th>
                        <th scope="col" class="manage-column"><?php echo esc_html__('Actions', 'cloudtart-support'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allowed_keywords as $index => $keyword): ?>
                        <tr data-index="<?php echo esc_attr($index); ?>">
                            <td><input type="text" name="cloudtart_internal_cdn_settings[allowed_keywords][<?php echo esc_attr($index); ?>]" value="<?php echo esc_attr($keyword); ?>" class="large-text"></td>
                            <td><button type="button" class="button button-secondary remove-allowed-keyword"><?php echo esc_html__('Remove', 'cloudtart-support'); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="display:none;">
                    <tr id="allowed-keyword-template">
                        <td><input type="text" name="" value="" class="large-text"></td>
                        <td><button type="button" class="button button-secondary remove-allowed-keyword"><?php echo esc_html__('Remove', 'cloudtart-support'); ?></button></td>
                    </tr>
                </tfoot>
            </table>
            <button type="button" class="button button-primary" id="add-allowed-keyword" style="margin-top: 10px;"><?php echo esc_html__('Add Keyword', 'cloudtart-support'); ?></button>
            <p class="description"><?php echo esc_html__('If the host name of a URL contains any of these keywords (for example shaparak or payamak), it is allowed even if no replacement rule is defined.', 'cloudtart-support'); ?></p>
        </div>
        <?php
    }

    /**
     * نمایش فیلد فعال‌سازی درگاه IPPanel
     */
    public function render_enable_digits_iranpayamak_field() {
        $enable_digits_iranpayamak = isset($this->plugins_settings['enable_digits_iranpayamak']) && $this->plugins_settings['enable_digits_iranpayamak'] == '1';
        
        $is_digits_active = $this->is_plugin_active_by_files(
            ['digits/digits.php', 'digits-pro/digits-pro.php', 'digits-pro/digits.php'],
            [defined('DIGITS_VERSION'), class_exists('Digits'), function_exists('digits_version')]
        );
        
        $disabled_attr = $is_digits_active ? '' : 'disabled';
        $label_style = $is_digits_active ? '' : 'style="opacity: 0.6; cursor: not-allowed;"';
        ?>
        <label class="ct-check" <?php echo $label_style; ?>>
            <input type="hidden" name="cloudtart_plugins_settings[enable_digits_iranpayamak]" value="0">
            <input type="checkbox" name="cloudtart_plugins_settings[enable_digits_iranpayamak]" value="1" <?php checked($enable_digits_iranpayamak); ?> <?php echo $disabled_attr; ?>>
            <span><?php echo esc_html__('Enable the IranPayamak (FarazSMS) SMS gateway inside the "Digits" plugin', 'cloudtart-support'); ?></span>
        </label>
        <p class="description" <?php echo $label_style; ?>><?php echo esc_html__('When enabled, the IranPayamak SMS gateway is added to the Digits plugin (Digits — WordPress Mobile Number Signup and Login).', 'cloudtart-support'); ?></p>
        <?php if (!$is_digits_active): ?>
            <p class="description" style="color: #d63638;"><?php echo esc_html__('To enable this option, install and activate the "Digits — WordPress Mobile Number Signup and Login" plugin first.', 'cloudtart-support'); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * نمایش فیلد فعال‌سازی فیکس Dokan
     */
    public function render_enable_dokan_fix_field() {
        $enable_dokan_fix = isset($this->plugins_settings['enable_dokan_fix']) && $this->plugins_settings['enable_dokan_fix'] == '1';
        
        $is_dokan_active = $this->is_plugin_active_by_files(
            ['dokan-lite/dokan.php', 'dokan-pro/dokan-pro.php'],
            [class_exists('WeDevs_Dokan'), defined('DOKAN_PLUGIN_VERSION'), function_exists('dokan')]
        );
        
        $disabled_attr = $is_dokan_active ? '' : 'disabled';
        $label_style = $is_dokan_active ? '' : 'style="opacity: 0.6; cursor: not-allowed;"';
        ?>
        <label class="ct-check" <?php echo $label_style; ?>>
            <input type="hidden" name="cloudtart_plugins_settings[enable_dokan_fix]" value="0">
            <input type="checkbox" name="cloudtart_plugins_settings[enable_dokan_fix]" value="1" <?php checked($enable_dokan_fix); ?> <?php echo $disabled_attr; ?>>
            <span><?php echo esc_html__('Enable the coupon limitation fix for the "Dokan Multivendor" plugin', 'cloudtart-support'); ?></span>
        </label>
        <p class="description" <?php echo $label_style; ?>><?php echo esc_html__('This option fixes coupon restriction issues introduced by the Dokan plugin (Dokan – Best WooCommerce Multivendor Marketplace Solution) and restores the default WooCommerce coupon validation behavior.', 'cloudtart-support'); ?></p>
        <?php if (!$is_dokan_active): ?>
            <p class="description" style="color: #d63638;"><?php echo esc_html__('To enable this option, install and activate the "Dokan – Best WooCommerce Multivendor Marketplace Solution" plugin first.', 'cloudtart-support'); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * نمایش فیلد فعال‌سازی ماژول سفارشات برداشت کیف پول
     */
    public function render_enable_wallet_withdraw_orders_field() {
        $enable_wallet_withdraw_orders = isset($this->plugins_settings['enable_wallet_withdraw_orders']) && $this->plugins_settings['enable_wallet_withdraw_orders'] == '1';

        $is_wallet_active = $this->is_plugin_active_by_files(
            ['FS_WooCommerce_Wallet/main.php', 'fs-woocommerce-wallet/main.php', 'woo-wallet/main.php'],
            [class_exists('FS_WC_Wallet'), class_exists('Wallet')]
        );

        $disabled_attr = $is_wallet_active ? '' : 'disabled';
        $label_style = $is_wallet_active ? '' : 'style="opacity: 0.6; cursor: not-allowed;"';
        ?>
        <label class="ct-check" <?php echo $label_style; ?>>
            <input type="hidden" name="cloudtart_plugins_settings[enable_wallet_withdraw_orders]" value="0">
            <input type="checkbox" name="cloudtart_plugins_settings[enable_wallet_withdraw_orders]" value="1" <?php checked($enable_wallet_withdraw_orders); ?> <?php echo $disabled_attr; ?>>
            <span><?php echo esc_html__('Manage withdrawal requests of the "FS WooCommerce Wallet" plugin as WooCommerce orders', 'cloudtart-support'); ?></span>
        </label>
        <p class="description" <?php echo $label_style; ?>><?php echo esc_html__('When enabled, withdrawal requests submitted through the FS WooCommerce Wallet plugin create dedicated WooCommerce orders, and admins can settle or reject payouts using custom order statuses.', 'cloudtart-support'); ?></p>
        <?php if (!$is_wallet_active): ?>
            <p class="description" style="color: #d63638;"><?php echo esc_html__('To enable this option, install and activate the "FS WooCommerce Wallet" plugin first.', 'cloudtart-support'); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * نمایش فیلد فعال‌سازی درگاه ایران پیامک برای پیامک ووکامرس فارسی
     */
    public function render_enable_pwsms_iranpayamak_field() {
        $enable_pwsms_iranpayamak = isset($this->plugins_settings['enable_pwsms_iranpayamak']) && $this->plugins_settings['enable_pwsms_iranpayamak'] == '1';

        $is_pwsms_active = $this->is_plugin_active_by_files(
            ['persian-woocommerce-sms/WoocommerceIR_SMS.php'],
            [defined('PWSMS_VERSION'), function_exists('PWSMS')]
        );

        $disabled_attr = $is_pwsms_active ? '' : 'disabled';
        $label_style = $is_pwsms_active ? '' : 'style="opacity: 0.6; cursor: not-allowed;"';
        ?>
        <label class="ct-check" <?php echo $label_style; ?>>
            <input type="hidden" name="cloudtart_plugins_settings[enable_pwsms_iranpayamak]" value="0">
            <input type="checkbox" name="cloudtart_plugins_settings[enable_pwsms_iranpayamak]" value="1" <?php checked($enable_pwsms_iranpayamak); ?> <?php echo $disabled_attr; ?>>
            <span><?php echo esc_html__('Enable the IranPayamak (FarazSMS) gateway inside the "Persian WooCommerce SMS" plugin', 'cloudtart-support'); ?></span>
        </label>
        <p class="description" <?php echo $label_style; ?>><?php echo esc_html__('When enabled, a dedicated IranPayamak gateway (with API Key authentication) is added to the Persian WooCommerce SMS plugin.', 'cloudtart-support'); ?></p>
        <?php if (!$is_pwsms_active): ?>
            <p class="description" style="color: #d63638;"><?php echo esc_html__('To enable this option, install and activate the "Persian WooCommerce SMS" plugin first.', 'cloudtart-support'); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * نمایش فیلد فعال‌سازی پخش آنلاین فایل‌های صوتی ووکامرس
     */
    public function render_enable_wc_audio_stream_field() {
        $enabled = isset($this->plugins_settings['enable_wc_audio_stream']) && $this->plugins_settings['enable_wc_audio_stream'] === '1';
        $is_wc_active = $this->is_woocommerce_active();

        $disabled_attr = $is_wc_active ? '' : 'disabled';
        $label_style = $is_wc_active ? '' : 'style="opacity: 0.6; cursor: not-allowed;"';
        ?>
        <label class="ct-check" <?php echo $label_style; ?>>
            <input type="hidden" name="cloudtart_plugins_settings[enable_wc_audio_stream]" value="0">
            <input type="checkbox" id="cloudtart-enable-wc-audio-stream" name="cloudtart_plugins_settings[enable_wc_audio_stream]" value="1" <?php checked($enabled); ?> <?php echo $disabled_attr; ?>>
            <span><?php echo esc_html__('Show purchased audio files as streamable tracks instead of download links', 'cloudtart-support'); ?></span>
        </label>
        <p class="description" <?php echo $label_style; ?>><?php echo esc_html__('In My Account → Downloads and on the order page, audio files (MP3, M4A, WAV, OGG, FLAC, …) get a play button and are served through a signed, expiring streaming link. Other file types keep their normal download link.', 'cloudtart-support'); ?></p>
        <?php if (!$is_wc_active): ?>
            <p class="description" style="color: #d63638;"><?php echo esc_html__('To enable this option, install and activate the "WooCommerce" plugin first.', 'cloudtart-support'); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * نمایش گزینه‌های پخش آنلاین (فقط وقتی قابلیت فعال باشد)
     */
    public function render_wc_audio_stream_options_field() {
        $enabled = isset($this->plugins_settings['enable_wc_audio_stream']) && $this->plugins_settings['enable_wc_audio_stream'] === '1';
        $allow_download = isset($this->plugins_settings['wc_audio_allow_download']) && $this->plugins_settings['wc_audio_allow_download'] === '1';
        $show_player = !isset($this->plugins_settings['wc_audio_show_player']) || $this->plugins_settings['wc_audio_show_player'] !== '0';
        $theme = (isset($this->plugins_settings['wc_audio_player_theme']) && $this->plugins_settings['wc_audio_player_theme'] === 'light') ? 'light' : 'dark';
        ?>
        <p id="wc-audio-stream-hint" class="description" <?php echo $enabled ? 'style="display:none"' : ''; ?>>
            <?php echo esc_html__('To configure these options, first turn on "WooCommerce — stream purchased audio files".', 'cloudtart-support'); ?>
        </p>
        <div id="wc-audio-stream-options" class="ct-suboptions" <?php echo $enabled ? '' : 'style="display:none"'; ?>>
            <div class="ct-suboption">
                <label class="ct-check">
                    <input type="hidden" name="cloudtart_plugins_settings[wc_audio_allow_download]" value="0">
                    <input type="checkbox" name="cloudtart_plugins_settings[wc_audio_allow_download]" value="1" <?php checked($allow_download); ?>>
                    <span><?php echo esc_html__('Allow direct download as well', 'cloudtart-support'); ?></span>
                </label>
                <p class="description"><?php echo esc_html__('When off, the WooCommerce download link for audio files is removed and direct download requests are blocked; customers can only listen online. When on, a download icon is shown next to each play button.', 'cloudtart-support'); ?></p>
            </div>

            <div class="ct-suboption">
                <label class="ct-check">
                    <input type="hidden" name="cloudtart_plugins_settings[wc_audio_show_player]" value="0">
                    <input type="checkbox" name="cloudtart_plugins_settings[wc_audio_show_player]" value="1" <?php checked($show_player); ?>>
                    <span><?php echo esc_html__('Show the sticky playlist player', 'cloudtart-support'); ?></span>
                </label>
                <p class="description"><?php echo esc_html__('A fixed player at the bottom of the page lists every audio track of that page and plays them in order. When off, each track gets a simple inline player instead.', 'cloudtart-support'); ?></p>
            </div>

            <div class="ct-suboption">
                <label for="cloudtart-wc-audio-theme" class="ct-suboption__label"><?php echo esc_html__('Player theme', 'cloudtart-support'); ?></label>
                <select id="cloudtart-wc-audio-theme" name="cloudtart_plugins_settings[wc_audio_player_theme]">
                    <option value="dark" <?php selected($theme, 'dark'); ?>><?php echo esc_html__('Dark', 'cloudtart-support'); ?></option>
                    <option value="light" <?php selected($theme, 'light'); ?>><?php echo esc_html__('Light', 'cloudtart-support'); ?></option>
                </select>
                <div class="ct-theme-preview" aria-hidden="true">
                    <span class="ct-theme-swatch ct-theme-swatch--dark <?php echo $theme === 'dark' ? 'is-selected' : ''; ?>" data-theme="dark"><i></i><i></i><i></i></span>
                    <span class="ct-theme-swatch ct-theme-swatch--light <?php echo $theme === 'light' ? 'is-selected' : ''; ?>" data-theme="light"><i></i><i></i><i></i></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * نمایش فیلد حالت دیباگ
     */
    public function render_debug_field() {
        $debug = isset($this->settings['debug']) && $this->settings['debug'] == '1';
        ?>
        <label class="ct-check">
            <input type="hidden" name="cloudtart_support_settings[debug]" value="0">
            <input type="checkbox" name="cloudtart_support_settings[debug]" value="1" <?php checked($debug); ?>>
            <span><?php echo esc_html__('Enable debug mode (more logs for troubleshooting)', 'cloudtart-support'); ?></span>
        </label>
        <p class="description"><?php echo esc_html__('When enabled, plugin activity logs are stored under the uploads directory so they survive plugin updates.', 'cloudtart-support'); ?></p>
        <?php
    }

    /**
     * نمایش فیلد تاریخ انقضای دامنه
     */
    public function render_domain_expiry_field() {
        $domain_expiry = isset($this->expiry_settings['domain_expiry']) ? $this->expiry_settings['domain_expiry'] : '';
        ?>
        <input type="date" name="cloudtart_expiry_settings[domain_expiry]" value="<?php echo esc_attr($domain_expiry); ?>" class="regular-text">
        <p class="description"><?php echo esc_html__('Enter the site domain expiry date.', 'cloudtart-support'); ?></p>
        <?php
    }
    
    /**
     * نمایش فیلد دوره تمدید دامنه
     */
    public function render_domain_period_field() {
        $domain_period = isset($this->expiry_settings['domain_period']) ? $this->expiry_settings['domain_period'] : '1';
        ?>
        <select name="cloudtart_expiry_settings[domain_period]" class="regular-text">
            <?php
            for ($i = 1; $i <= 10; $i++) {
                echo '<option value="' . $i . '" ' . selected($domain_period, $i, false) . '>' . esc_html(sprintf(__('%s year', 'cloudtart-support'), $i)) . '</option>';
            }
            ?>
        </select>
        <p class="description"><?php echo esc_html__('Select the domain renewal period.', 'cloudtart-support'); ?></p>
        <?php
    }
    
    /**
     * نمایش فیلد تاریخ انقضای هاست
     */
    public function render_hosting_expiry_field() {
        $hosting_expiry = isset($this->expiry_settings['hosting_expiry']) ? $this->expiry_settings['hosting_expiry'] : '';
        ?>
        <input type="date" name="cloudtart_expiry_settings[hosting_expiry]" value="<?php echo esc_attr($hosting_expiry); ?>" class="regular-text">
        <p class="description"><?php echo esc_html__('Enter the hosting expiry date.', 'cloudtart-support'); ?></p>
        <?php
    }
    
    /**
     * نمایش فیلد دوره تمدید هاست
     */
    public function render_hosting_period_field() {
        $hosting_period = isset($this->expiry_settings['hosting_period']) ? $this->expiry_settings['hosting_period'] : '1';
        ?>
        <select name="cloudtart_expiry_settings[hosting_period]" class="regular-text">
            <option value="1" <?php selected($hosting_period, '1'); ?>><?php echo esc_html(sprintf(__('%s month', 'cloudtart-support'), 1)); ?></option>
            <option value="3" <?php selected($hosting_period, '3'); ?>><?php echo esc_html(sprintf(__('%s month', 'cloudtart-support'), 3)); ?></option>
            <option value="6" <?php selected($hosting_period, '6'); ?>><?php echo esc_html(sprintf(__('%s month', 'cloudtart-support'), 6)); ?></option>
            <option value="12" <?php selected($hosting_period, '12'); ?>><?php echo esc_html(sprintf(__('%s year', 'cloudtart-support'), 1)); ?></option>
            <option value="24" <?php selected($hosting_period, '24'); ?>><?php echo esc_html(sprintf(__('%s year', 'cloudtart-support'), 2)); ?></option>
        </select>
        <p class="description"><?php echo esc_html__('Select the hosting renewal period.', 'cloudtart-support'); ?></p>
        <?php
    }
    
    /**
     * نمایش فیلد ایمیل‌های اطلاع‌رسانی
     */
    public function render_notification_emails_field() {
        $notification_emails = isset($this->expiry_settings['notification_emails']) ? $this->expiry_settings['notification_emails'] : '';
        ?>
        <input type="text" name="cloudtart_expiry_settings[notification_emails]" value="<?php echo esc_attr($notification_emails); ?>" class="large-text">
        <p class="description"><?php echo esc_html__('Enter email addresses for notifications. Use commas for multiple addresses (max 5).', 'cloudtart-support'); ?></p>
        <?php
    }
    
    /**
     * نمایش فیلد روزهای اطلاع‌رسانی
     */
    public function render_notification_days_field() {
        $notification_days = isset($this->expiry_settings['notification_days']) ? $this->expiry_settings['notification_days'] : '10';
        ?>
        <input type="number" name="cloudtart_expiry_settings[notification_days]" value="<?php echo esc_attr($notification_days); ?>" class="small-text" min="1" max="30">
        <p class="description"><?php echo esc_html__('How many days before expiry should notifications be sent?', 'cloudtart-support'); ?></p>
        <?php
    }
    
    /**
     * نمایش فیلد مانیتورینگ وضعیت سایت
     */
    public function render_status_monitoring_field() {
        $status_monitoring = isset($this->monitoring_settings['status_monitoring']) && $this->monitoring_settings['status_monitoring'] == '1';
        ?>
        <label class="ct-check">
            <input type="hidden" name="cloudtart_monitoring_settings[status_monitoring]" value="0">
            <input type="checkbox" name="cloudtart_monitoring_settings[status_monitoring]" value="1" <?php checked($status_monitoring); ?>>
            <span><?php echo esc_html__('Enable site status checks and HTTP error notifications (e.g., 503, 403)', 'cloudtart-support'); ?></span>
        </label>
        <?php
    }
    
    /**
     * نمایش فیلد مانیتورینگ خطاهای PHP
     */
    public function render_error_monitoring_field() {
        $error_monitoring = isset($this->monitoring_settings['error_monitoring']) && $this->monitoring_settings['error_monitoring'] == '1';
        ?>
        <label class="ct-check">
            <input type="hidden" name="cloudtart_monitoring_settings[error_monitoring]" value="0">
            <input type="checkbox" name="cloudtart_monitoring_settings[error_monitoring]" value="1" <?php checked($error_monitoring); ?>>
            <span><?php echo esc_html__('Enable logging and notifications for PHP errors', 'cloudtart-support'); ?></span>
        </label>
        <?php
    }
    
    /**
     * نمایش فیلد ایمیل اطلاع‌رسانی مانیتورینگ
     */
    public function render_notification_email_field() {
        $notification_email = isset($this->monitoring_settings['notification_email']) ? $this->monitoring_settings['notification_email'] : get_option('admin_email');
        ?>
        <input type="text" name="cloudtart_monitoring_settings[notification_email]" value="<?php echo esc_attr($notification_email); ?>" class="regular-text">
        <p class="description"><?php echo esc_html__('Enter email addresses to receive error reports.', 'cloudtart-support'); ?></p>
        <?php
    }
    
    /**
     * نمایش فیلد فاصله زمانی بررسی
     */
    public function render_check_interval_field() {
        $check_interval = isset($this->monitoring_settings['check_interval']) ? $this->monitoring_settings['check_interval'] : 'hourly';
        ?>
        <select name="cloudtart_monitoring_settings[check_interval]" class="regular-text">
            <option value="hourly" <?php selected($check_interval, 'hourly'); ?>><?php echo esc_html__('Hourly', 'cloudtart-support'); ?></option>
            <option value="twicedaily" <?php selected($check_interval, 'twicedaily'); ?>><?php echo esc_html__('Twice Daily', 'cloudtart-support'); ?></option>
            <option value="daily" <?php selected($check_interval, 'daily'); ?>><?php echo esc_html__('Daily', 'cloudtart-support'); ?></option>
        </select>
        <p class="description"><?php echo esc_html__('Status check interval.', 'cloudtart-support'); ?></p>
        <?php
    }

    /**
     * آیکون‌های خطی تب درباره ما
     *
     * همه روی شبکه ۲۴ پیکسلی و با ضخامت یکسان رسم شده‌اند و رنگشان را از
     * currentColor می‌گیرند تا با پالت برند هماهنگ بمانند.
     */
    private function render_about_icon($name) {
        $paths = [
            'design' => [
                'M4.2 19.8l1.1-3.9a2 2 0 0 1 .52-.9l8.4-8.4a2.1 2.1 0 0 1 3 0l.28.28a2.1 2.1 0 0 1 0 3l-8.4 8.4a2 2 0 0 1-.9.52l-3.9 1.1Z',
                'M12.9 7.5l3.6 3.6',
                'M18.6 2.6l.55 1.45 1.45.55-1.45.55-.55 1.45-.55-1.45-1.45-.55 1.45-.55.55-1.45Z',
            ],
            'code' => [
                'M8.6 7.4L4 12l4.6 4.6',
                'M15.4 7.4L20 12l-4.6 4.6',
                'M13.4 4.4l-2.8 15.2',
            ],
            'seo' => [
                'M10.5 4a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13Z',
                'M20.4 20.4l-5.2-5.2',
                'M8 13.4v-1.8M10.5 13.4V9.6M13 13.4v-2.9',
            ],
            'megaphone' => [
                'M4 10.4v3.2a1.5 1.5 0 0 0 1.5 1.5H7l7.1 4.2a.8.8 0 0 0 1.2-.7V5.4a.8.8 0 0 0-1.2-.7L7 8.9H5.5A1.5 1.5 0 0 0 4 10.4Z',
                'M18.6 9.4a3.6 3.6 0 0 1 0 5.2',
                'M8.2 15.3v3.4a1.3 1.3 0 0 0 2.6 0v-1.9',
            ],
            'support' => [
                'M4.6 13.6v-1.9a7.4 7.4 0 0 1 14.8 0v1.9',
                'M4.6 13.2h1.5a1.4 1.4 0 0 1 1.4 1.4v2.3a1.4 1.4 0 0 1-1.4 1.4H6a1.4 1.4 0 0 1-1.4-1.4v-3.7Z',
                'M19.4 13.2h-1.5a1.4 1.4 0 0 0-1.4 1.4v2.3a1.4 1.4 0 0 0 1.4 1.4h.1a1.4 1.4 0 0 0 1.4-1.4v-3.7Z',
                'M19.4 17.9v.4a2.6 2.6 0 0 1-2.6 2.6h-2.3',
            ],
            'gem' => [
                'M7.4 3.4h9.2l3.4 5.1-8 12.1-8-12.1 3.4-5.1Z',
                'M3.6 8.5h16.8',
                'M9.6 8.5L12 20.6l2.4-12.1',
                'M7.4 3.4l2.2 5.1M16.6 3.4l-2.2 5.1',
            ],
            'sparkle' => [
                'M11.2 3.4l1.6 4.4 4.4 1.6-4.4 1.6-1.6 4.4-1.6-4.4-4.4-1.6 4.4-1.6 1.6-4.4Z',
                'M18.2 15.2l.7 1.9 1.9.7-1.9.7-.7 1.9-.7-1.9-1.9-.7 1.9-.7.7-1.9Z',
            ],
            'bolt' => [
                'M13.4 2.8L5.6 13.4h5.5l-.5 7.8 7.8-10.6h-5.5l.5-7.8Z',
            ],
            'shield' => [
                'M12 2.9l7 2.6v5.2c0 4.3-2.9 8.2-7 9.4-4.1-1.2-7-5.1-7-9.4V5.5l7-2.6Z',
                'M8.9 11.8l2.2 2.2 4-4.2',
            ],
            'growth' => [
                'M3.6 16.4l4.9-4.9 3.5 3.5 5.9-5.9',
                'M14.3 9.1h3.6v3.6',
                'M3.6 20.4h16.8',
            ],
        ];

        if (!isset($paths[$name])) {
            return;
        }

        echo '<svg class="ct-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">';
        foreach ($paths[$name] as $path) {
            printf(
                '<path d="%s" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
                esc_attr($path)
            );
        }
        echo '</svg>';
    }

    /**
     * نمایش صفحه تنظیمات پلاگین
     */
    public function render_settings_page() {
        // تعیین تب فعال - تب پیش‌فرض تاریخ‌های انقضا
        $valid_tabs = $this->get_valid_tabs();
        $active_tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'expiry';
        if (!in_array($active_tab, $valid_tabs, true)) {
            $active_tab = 'expiry';
        }

        $save_notice = false;
        if (isset($_GET['cloudtart_saved']) && $_GET['cloudtart_saved'] === '1') {
            $notice_key = 'cloudtart_support_save_notice_' . get_current_user_id();
            $payload = get_transient($notice_key);
            if ($payload !== false) {
                delete_transient($notice_key);
                $save_notice = is_array($payload) ? $payload : ['saved_groups' => []];
            }
        }

        $save_label = __('Save All Settings', 'cloudtart-support');
        $save_label_id = 'cloudtart-save-all-button';
        $tabs = $this->get_settings_tabs();
        $builtin_tabs = ['expiry', 'monitoring', 'logs', 'general', 'plugins', 'internal_cdn', 'about'];
        $connector_active = cloudtart_support_connector_active();
        ?>
        <div class="wrap cloudtart-support-wrap">
            <!-- Admin Header with Flexbox -->
                <div class="cloudtart-admin-header">
                    <img src="<?php echo esc_url(CLOUDTART_SUPPORT_URL . 'assets/img/cloudtart-logo-h-en.png'); ?>" alt="CloudTart Logo" class="cloudtart-logo small-left" />
                    <div class="header-text">
                        <h1><?php echo esc_html__('CloudTart Support', 'cloudtart-support'); ?></h1>
                        <p><?php echo esc_html__('Manage your site settings and monitoring.', 'cloudtart-support'); ?></p>
                    </div>
                </div>

            <?php if ($save_notice !== false): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('All settings across every tab have been saved.', 'cloudtart-support'); ?></p>
                </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_id => $tab_label): ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'cloudtart-support', 'tab' => $tab_id], admin_url('options-general.php'))); ?>" class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($tab_label); ?></a>
                <?php endforeach; ?>
            </h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="cloudtart-unified-form">
                <input type="hidden" name="action" value="cloudtart_save_all_settings">
                <input type="hidden" name="cloudtart_active_tab" id="cloudtart_active_tab" value="<?php echo esc_attr($active_tab); ?>">
                <?php
                // Declare which settings groups this page rendered. The save
                // handler writes exactly these, so a tab whose every checkbox is
                // cleared still gets persisted instead of being skipped.
                foreach (array_keys($this->get_settings_groups()) as $settings_group) {
                    printf(
                        '<input type="hidden" name="cloudtart_settings_groups[]" value="%s">',
                        esc_attr($settings_group)
                    );
                }
                wp_nonce_field('cloudtart_save_all_settings', 'cloudtart_save_all_nonce');
                ?>

                <div id="expiry" class="tab-content<?php echo $active_tab === 'expiry' ? ' show' : ''; ?>" <?php echo $active_tab != 'expiry' ? 'style="display:none"' : ''; ?>>
                    <?php
                    do_settings_sections('cloudtart-expiry');

                    // نمایش پیش‌نمایش تاریخ‌ها
                    $this->expiry_manager->display_expiry_preview();

                    submit_button($save_label, 'primary', 'submit', true, ['id' => $save_label_id]);

                    // نمایش بخش مدیریت دامنه‌های چندگانه (UI با AJAX خودش)
                    $this->expiry_manager->display_multiple_domains();
                    ?>
                </div>

                <div id="monitoring" class="tab-content<?php echo $active_tab === 'monitoring' ? ' show' : ''; ?>" <?php echo $active_tab != 'monitoring' ? 'style="display:none"' : ''; ?>>
                    <?php
                    do_settings_sections('cloudtart-monitoring');

                    // نمایش تاریخچه بررسی‌ها
                    $this->display_monitoring_history();

                    submit_button($save_label, 'primary', 'submit', true, ['id' => $save_label_id . '-monitoring']);
                    ?>
                </div>

                <div id="logs" class="tab-content<?php echo $active_tab === 'logs' ? ' show' : ''; ?>" <?php echo $active_tab != 'logs' ? 'style="display:none"' : ''; ?>>
                    <div class="cloudtart-support-logs">
                        <h3><?php echo esc_html__('Recent Logs', 'cloudtart-support'); ?></h3>
                        <div class="d-flex justify-content-end mb-2">
                            <button type="button" class="button" id="refresh-logs"><?php echo esc_html__('Refresh Logs', 'cloudtart-support'); ?></button>
                        </div>
                        <textarea readonly rows="20" class="large-text code" id="logs-content"><?php echo esc_textarea($this->logger->get_logs(30)); ?></textarea>

                        <h3 class="mt-4"><?php echo esc_html__('PHP Error Logs', 'cloudtart-support'); ?></h3>
                        <textarea readonly rows="10" class="large-text code" id="error-logs-content"><?php echo esc_textarea($this->logger->get_error_logs(10)); ?></textarea>
                    </div>
                </div>

                <div id="general" class="tab-content<?php echo $active_tab === 'general' ? ' show' : ''; ?>" <?php echo $active_tab != 'general' ? 'style="display:none"' : ''; ?>>
                    <?php
                    do_settings_sections('cloudtart-support');
                    submit_button($save_label, 'primary', 'submit', true, ['id' => $save_label_id . '-general']);
                    ?>

                    <div class="cloudtart-support-status">
                        <h2><?php echo esc_html__('System Status', 'cloudtart-support'); ?></h2>
                        <table class="widefat">
                            <tr>
                                <th><?php echo esc_html__('Plugin Version:', 'cloudtart-support'); ?></th>
                                <td><?php echo esc_html(CLOUDTART_SUPPORT_VERSION); ?></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html__('Debug Status:', 'cloudtart-support'); ?></th>
                                <td>
                                    <?php if (isset($this->settings['debug']) && $this->settings['debug'] == '1'): ?>
                                        <span class="status-ok"><?php echo esc_html__('Enabled', 'cloudtart-support'); ?></span>
                                    <?php else: ?>
                                        <span class="status-info"><?php echo esc_html__('Disabled', 'cloudtart-support'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html__('Log Path:', 'cloudtart-support'); ?></th>
                                <td>
                                    <?php
                                    $log_dir_info = function_exists('cloudtart_support_get_log_dir')
                                        ? cloudtart_support_get_log_dir()
                                        : ['basedir' => CLOUDTART_SUPPORT_DIR . 'logs'];
                                    $log_dir = $log_dir_info['basedir'];
                                    if (is_dir($log_dir) && is_writable($log_dir)) {
                                        echo '<span class="status-ok">' . sprintf(esc_html__('%1$s (writable)', 'cloudtart-support'), esc_html($log_dir)) . '</span>';
                                    } elseif (is_dir($log_dir)) {
                                        echo '<span class="status-error">' . sprintf(esc_html__('%1$s (not writable)', 'cloudtart-support'), esc_html($log_dir)) . '</span>';
                                    } else {
                                        echo '<span class="status-warning">' . esc_html__('Log directory has not been created yet', 'cloudtart-support') . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php
                            /**
                             * Companion plugins (the CloudTart connector) add their own rows,
                             * e.g. the connection status to the support platform.
                             */
                            do_action('cloudtart_support_system_status_rows');
                            ?>
                        </table>
                    </div>

                    <?php if (!$connector_active): ?>
                        <div class="cloudtart-contact-card">
                            <div class="cloudtart-contact-card__icon" aria-hidden="true"><?php $this->render_about_icon('support'); ?></div>
                            <div class="cloudtart-contact-card__body">
                                <h3><?php echo esc_html__('CloudTart support services', 'cloudtart-support'); ?></h3>
                                <p><?php echo esc_html__('Remote updates, uptime reporting and error forwarding are available to CloudTart support customers. If you need CloudTart support services, please contact us.', 'cloudtart-support'); ?></p>
                                <a class="button button-secondary" href="https://cloudtart.com/contact-us/" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Contact Us', 'cloudtart-support'); ?></a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="plugins" class="tab-content<?php echo $active_tab === 'plugins' ? ' show' : ''; ?>" <?php echo $active_tab != 'plugins' ? 'style="display:none"' : ''; ?>>
                    <?php
                    do_settings_sections('cloudtart-plugins');
                    submit_button($save_label, 'primary', 'submit', true, ['id' => $save_label_id . '-plugins']);
                    ?>
                </div>

                <div id="internal_cdn" class="tab-content<?php echo $active_tab === 'internal_cdn' ? ' show' : ''; ?>" <?php echo $active_tab != 'internal_cdn' ? 'style="display:none"' : ''; ?>>
                    <?php
                    do_settings_sections('cloudtart-internal-cdn');
                    submit_button($save_label, 'primary', 'submit', true, ['id' => $save_label_id . '-internal-cdn']);
                    ?>
                </div>

                <?php foreach ($tabs as $tab_id => $tab_label): ?>
                    <?php if (in_array($tab_id, $builtin_tabs, true)) { continue; } ?>
                    <div id="<?php echo esc_attr($tab_id); ?>" class="tab-content<?php echo $active_tab === $tab_id ? ' show' : ''; ?>" <?php echo $active_tab !== $tab_id ? 'style="display:none"' : ''; ?>>
                        <?php
                        /**
                         * Content of a tab registered through the
                         * cloudtart_support_settings_tabs filter.
                         */
                        do_action('cloudtart_support_render_tab_' . $tab_id, $active_tab);
                        do_action('cloudtart_support_render_tab', $tab_id, $active_tab);
                        submit_button($save_label, 'primary', 'submit', true, ['id' => $save_label_id . '-' . $tab_id]);
                        ?>
                    </div>
                <?php endforeach; ?>

                <div id="about" class="tab-content<?php echo $active_tab === 'about' ? ' show' : ''; ?>" <?php echo $active_tab != 'about' ? 'style="display:none"' : ''; ?>>
            <div class="cloudtart-about luxurious">
                <div class="hero-banner">
                    <div class="hero-overlay"></div>
                    <div class="hero-content">
                        <img src="<?php echo esc_url(CLOUDTART_SUPPORT_URL . 'assets/img/cloudtart-logo-v-fa.png'); ?>" alt="<?php echo esc_attr__('CloudTart', 'cloudtart-support'); ?>" class="cloudtart-logo-vertical hero-logo" />
                        <h2><?php echo esc_html__('CloudTart | Beyond code, the art of digital architecture', 'cloudtart-support'); ?></h2>
                        <p class="hero-tagline"><?php echo esc_html__('With minimalist and distinctive designs, we turn your website into an unforgettable experience.', 'cloudtart-support'); ?></p>
                    </div>
                </div>

                <section class="about-intro">
                    <h3><?php echo esc_html__('Our Story', 'cloudtart-support'); ?></h3>
                    <p><?php echo esc_html__('With a team of creative specialists, CloudTart brings over a decade of experience in designing and developing professional websites. We believe every project is an opportunity to create beauty and performance.', 'cloudtart-support'); ?></p>
                </section>

                <section class="services-section">
                    <h3><?php echo esc_html__('Our Services', 'cloudtart-support'); ?></h3>
                    <div class="services-grid">
                        <?php foreach ([
                            ['icon' => 'design',      'title' => __('Custom Design', 'cloudtart-support'),         'text' => __('Beautiful, user-friendly interfaces with attention to detail.', 'cloudtart-support')],
                            ['icon' => 'code',        'title' => __('Advanced Development', 'cloudtart-support'),  'text' => __('Powerful websites with clean, optimized code for high performance.', 'cloudtart-support')],
                            ['icon' => 'seo',         'title' => __('SEO and Marketing', 'cloudtart-support'),     'text' => __('Smart strategies to boost engagement and grow your business.', 'cloudtart-support')],
                            ['icon' => 'megaphone',   'title' => __('Digital Marketing', 'cloudtart-support'),     'text' => __('Creative campaigns to attract target audiences and increase sales.', 'cloudtart-support')],
                            ['icon' => 'support',     'title' => __('Full Support', 'cloudtart-support'),          'text' => __('Dedicated after-sales services focused on complete customer satisfaction.', 'cloudtart-support')],
                            ['icon' => 'gem',         'title' => __('Digital Branding', 'cloudtart-support'),      'text' => __('Create a unique visual identity for your brand online.', 'cloudtart-support')],
                        ] as $service): ?>
                            <div class="service-card">
                                <div class="icon-wrapper"><?php $this->render_about_icon($service['icon']); ?></div>
                                <h4><?php echo esc_html($service['title']); ?></h4>
                                <p><?php echo esc_html($service['text']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="why-us">
                    <h3><?php echo esc_html__('Why CloudTart?', 'cloudtart-support'); ?></h3>
                    <div class="why-grid">
                        <?php foreach ([
                            ['icon' => 'sparkle', 'text' => __('Luxury, minimalist designs that set your brand apart.', 'cloudtart-support')],
                            ['icon' => 'bolt',    'text' => __('Optimized performance and speed for excellent user experience.', 'cloudtart-support')],
                            ['icon' => 'shield',  'text' => __('Advanced security and adherence to global standards.', 'cloudtart-support')],
                            ['icon' => 'growth',  'text' => __('Growth-focused strategies for long-term success.', 'cloudtart-support')],
                        ] as $reason): ?>
                            <div class="why-item">
                                <span class="why-icon"><?php $this->render_about_icon($reason['icon']); ?></span>
                                <p><?php echo esc_html($reason['text']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="cta-section">
                    <h3><?php echo esc_html__('Ready to get started?', 'cloudtart-support'); ?></h3>
                    <p><?php echo esc_html__('Contact us and bring your dream project to life.', 'cloudtart-support'); ?></p>
                    <div class="cta-buttons">
                        <a href="https://cloudtart.com/contact-us/" target="_blank" class="button cta-primary"><?php echo esc_html__('Contact Us', 'cloudtart-support'); ?></a>
                        <a href="https://cloudtart.com/about-us/" target="_blank" class="button cta-secondary"><?php echo esc_html__('Learn More', 'cloudtart-support'); ?></a>
                    </div>
                </section>
            </div>
            </div>
            </form>
        </div>
        <?php
    }
    
    public function download_internal_cdn_log() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'cloudtart-support'));
        }

        check_admin_referer('cloudtart_internal_cdn_download_log');

        $log_file_info = $this->get_internal_cdn_log_file_info();
        $log_file = $log_file_info['file_path'];

        if (!is_file($log_file)) {
            wp_safe_redirect(admin_url('options-general.php?page=cloudtart-support&tab=internal_cdn&cloudtart_cdn_log=missing'));
            exit;
        }

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="cloudtart-internal-cdn-blocked-requests.log"');
        header('Content-Length: ' . filesize($log_file));

        readfile($log_file);
        exit;
    }

    public function delete_internal_cdn_log() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'cloudtart-support'));
        }

        check_admin_referer('cloudtart_internal_cdn_delete_log');

        $log_file_info = $this->get_internal_cdn_log_file_info();
        $log_file = $log_file_info['file_path'];
        $redirect_url = admin_url('options-general.php?page=cloudtart-support&tab=internal_cdn');

        if (!is_file($log_file)) {
            wp_safe_redirect(add_query_arg('cloudtart_cdn_log', 'missing', $redirect_url));
            exit;
        }

        if (@unlink($log_file)) {
            wp_safe_redirect(add_query_arg('cloudtart_cdn_log', 'deleted', $redirect_url));
            exit;
        }

        wp_safe_redirect(add_query_arg('cloudtart_cdn_log', 'delete_failed', $redirect_url));
        exit;
    }

    /**
     * نمایش تاریخچه بررسی‌های مانیتورینگ
     */
    private function display_monitoring_history() {
        if (!$this->monitoring) {
            return;
        }
        
        $logs = $this->monitoring->get_monitoring_logs();
        
        echo '<div class="monitoring-history">';
        echo '<h3>' . esc_html__('Check History', 'cloudtart-support') . '</h3>';
        
        if (empty($logs)) {
            echo '<p>' . esc_html__('No checks have been performed yet.', 'cloudtart-support') . '</p>';
        } else {
            echo '<table class="widefat sortable-table">';
            echo '<thead>';
            echo '<tr>';
            echo '<th data-sort="time">' . esc_html__('Date', 'cloudtart-support') . ' <i class="sort-icon"></i></th>';
            echo '<th data-sort="type">' . esc_html__('Type', 'cloudtart-support') . ' <i class="sort-icon"></i></th>';
            echo '<th data-sort="status">' . esc_html__('Status', 'cloudtart-support') . ' <i class="sort-icon"></i></th>';
            echo '<th data-sort="message">' . esc_html__('Details', 'cloudtart-support') . ' <i class="sort-icon"></i></th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            $recent_logs = array_slice($logs, -10); // نمایش 10 لاگ آخر
            foreach ($recent_logs as $log) {
                echo '<tr>';
                echo '<td>' . date('Y-m-d H:i:s', $log['time']) . '</td>';
                echo '<td>' . esc_html($log['type']) . '</td>';
                
                $status_class = '';
                $status_text = '';
                
                if ($log['status'] == 'success') {
                    $status_class = 'status-ok';
                    $status_text = __('Success', 'cloudtart-support');
                } elseif ($log['status'] == 'warning') {
                    $status_class = 'status-warning';
                    $status_text = __('Warning', 'cloudtart-support');
                } else {
                    $status_class = 'status-error';
                    $status_text = __('Error', 'cloudtart-support');
                }
                
                echo '<td><span class="' . $status_class . '">' . esc_html($status_text) . '</span></td>';
                echo '<td>' . esc_html($log['message']) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
        }
        
        echo '</div>';
    }
    
    /**
     * اکشن AJAX برای تازه‌سازی لاگ‌ها
     */
    public function ajax_refresh_logs() {
        // بررسی امنیتی
        check_ajax_referer('cloudtart_support_nonce', 'nonce');
        
        // بررسی دسترسی
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'cloudtart-support')]);
        }
        
        // دریافت لاگ‌ها
        $logs = $this->logger->get_logs(30);
        $error_logs = $this->logger->get_error_logs(10);
        
        wp_send_json_success([
            'logs' => $logs,
            'error_logs' => $error_logs
        ]);
    }
}
