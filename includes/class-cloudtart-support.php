<?php
/**
 * کلاس اصلی پلاگین
 */

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

class CloudTart_Support {
    private static $instance = null;
    private $settings = [];
    private $plugins_settings = [];
    private $debug = false;
    private $logger;
    private $monitoring;
    private $expiry_manager;
    private $admin;

    /**
     * دریافت نمونه منحصر به فرد از کلاس (الگوی Singleton)
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * سازنده کلاس
     */
    private function __construct() {
        // بارگذاری تنظیمات
        $this->load_settings();

        // راه‌اندازی ماژول‌های فعال
        $this->load_modules();

        // راه‌اندازی لاگر
        $this->logger = new CloudTart_Support_Logger($this->debug);

        // راه‌اندازی مانیتورینگ
        $this->monitoring = new CloudTart_Support_Monitoring($this->logger);

        // راه‌اندازی مدیریت تاریخ‌های انقضا
        $this->expiry_manager = new CloudTart_Support_Expiry_Manager($this->logger);

        // راه‌اندازی هوک‌ها
        $this->setup_hooks();

        // کارهای یک‌باره‌ی پس از هر ارتقای نسخه
        $this->maybe_run_upgrade_tasks();
    }

    /**
     * بارگذاری تنظیمات از دیتابیس
     */
    private function load_settings() {
        $this->settings = get_option('cloudtart_support_settings', [
            'api_key' => '',
            'debug' => '0'
        ]);

        $this->plugins_settings = get_option('cloudtart_plugins_settings', [
            'enable_digits_iranpayamak' => '0',
            'enable_dokan_fix' => '0',
            'enable_wallet_withdraw_orders' => '0',
            'enable_pwsms_iranpayamak' => '0',
            'enable_wc_audio_stream' => '0',
            'wc_audio_allow_download' => '0',
            'wc_audio_show_player' => '1',
            'wc_audio_player_theme' => 'dark'
        ]);

        // مهاجرت سازگار با تنظیمات قبلی
        if (
            !isset($this->plugins_settings['enable_digits_iranpayamak']) &&
            isset($this->plugins_settings['enable_digits_ippanel'])
        ) {
            $this->plugins_settings['enable_digits_iranpayamak'] = $this->plugins_settings['enable_digits_ippanel'] === '1' ? '1' : '0';
        }

        $this->debug = isset($this->settings['debug']) && $this->settings['debug'] == '1';
    }

    /**
     * بارگذاری ماژول‌های فعال
     */
    private function load_modules() {
        // بارگذاری درگاه IPPanel برای Digits
        if (isset($this->plugins_settings['enable_digits_iranpayamak']) && $this->plugins_settings['enable_digits_iranpayamak'] == '1') {
            require_once CLOUDTART_SUPPORT_DIR . 'modules/digits-gateways/class-digits-ippanel-loader.php';
            new CloudTart_Digits_IPPanel_Loader();
        }

        // بارگذاری فیکس کوپن‌های Dokan
        if (isset($this->plugins_settings['enable_dokan_fix']) && $this->plugins_settings['enable_dokan_fix'] == '1') {
            require_once CLOUDTART_SUPPORT_DIR . 'modules/dokan-fix/class-dokan-fix.php';
            new CloudTart_Support_Dokan_Fix();
        }

        // بارگذاری ماژول سفارش‌سازی برداشت کیف پول
        if (isset($this->plugins_settings['enable_wallet_withdraw_orders']) && $this->plugins_settings['enable_wallet_withdraw_orders'] == '1') {
            require_once CLOUDTART_SUPPORT_DIR . 'modules/wallet-withdraw-orders/class-wallet-withdraw-orders.php';
            new CloudTart_Support_Wallet_Withdraw_Orders();
        }

        // بارگذاری درگاه ایران پیامک برای Persian WooCommerce SMS
        if (isset($this->plugins_settings['enable_pwsms_iranpayamak']) && $this->plugins_settings['enable_pwsms_iranpayamak'] == '1') {
            require_once CLOUDTART_SUPPORT_DIR . 'modules/pwsms-gateways/class-pwsms-iranpayamak-loader.php';
            new CloudTart_PWSMS_IranPayamak_Loader();
        }

        // بارگذاری ماژول پخش آنلاین فایل‌های صوتی ووکامرس (فقط وقتی ووکامرس فعال است)
        if (
            isset($this->plugins_settings['enable_wc_audio_stream']) &&
            $this->plugins_settings['enable_wc_audio_stream'] == '1' &&
            class_exists('WooCommerce')
        ) {
            require_once CLOUDTART_SUPPORT_DIR . 'modules/wc-audio-stream/class-wc-audio-stream.php';
            new CloudTart_Support_WC_Audio_Stream($this->plugins_settings);
        }
    }

    /**
     * راه‌اندازی هوک‌های پلاگین
     */
    private function setup_hooks() {
        // هوک‌های فعال‌سازی و غیرفعال‌سازی
        register_activation_hook(CLOUDTART_SUPPORT_BASENAME, [$this, 'activate_plugin']);
        register_deactivation_hook(CLOUDTART_SUPPORT_BASENAME, [$this, 'deactivate_plugin']);

        // راه‌اندازی پنل ادمین پس از بارگذاری کامل WordPress
        add_action('init', [$this, 'init_admin_panel']);

        // نصب خودکار کانکتور روی سایت‌های مشتری که به پلتفرم متصل‌اند
        add_action('cloudtart_support_install_connector', [$this, 'install_connector']);
        add_action('admin_post_cloudtart_install_connector', [$this, 'handle_install_connector_request']);

        // گرفتن fatal error ها (فقط یک بار ثبت می‌شود؛ ثبت دوباره روی هوک shutdown
        // باعث می‌شد هر خطا دو بار ایمیل و گزارش شود)
        register_shutdown_function([$this, 'catch_fatal_error']);
    }

    /**
     * راه‌اندازی پنل ادمین
     */
    public function init_admin_panel() {
        // صفحات، AJAX و admin-post همه is_admin() هستند؛ بازدیدهای عادی سایت کلاس پیشخوان را لازم ندارند.
        if (!is_admin()) {
            return;
        }
        if (!$this->admin) {
            $this->admin = new CloudTart_Support_Admin($this->logger, $this->monitoring, $this->expiry_manager);
        }
    }

    /**
     * کارهایی که باید یک بار پس از ارتقای نسخه انجام شوند
     */
    private function maybe_run_upgrade_tasks() {
        $stored_version = get_option('cloudtart_support_version', '');
        if ($stored_version === CLOUDTART_SUPPORT_VERSION) {
            return;
        }

        add_action('init', [$this, 'run_upgrade_tasks'], 5);
    }

    public function run_upgrade_tasks() {
        // نام وابستگی‌ها («نیازمندی‌ها»ی کانکتور) از این cache خوانده می‌شود؛ با پاک
        // کردنش نام اصلاح‌شده بلافاصله نمایش داده می‌شود.
        delete_site_transient('wp_plugin_dependencies_plugin_data');

        // نسخه‌های قبلی یک کرون ۵ دقیقه‌ای برای ارسال وضعیت به پلتفرم داشتند که
        // حالا متعلق به کانکتور است. اگر کانکتور نصب نباشد، رویداد بی‌صاحب پاک می‌شود.
        if (!cloudtart_support_connector_active()) {
            $this->maybe_activate_connector();
            $this->maybe_schedule_connector_install();
        }

        if (!cloudtart_support_connector_active() && wp_next_scheduled('cloudtart_send_site_status')) {
            wp_clear_scheduled_hook('cloudtart_send_site_status');
        }

        // autoload: این option روی هر درخواست خوانده می‌شود؛ در کش alloptions باشد تا کوئری جداگانه نسازد.
        delete_option('cloudtart_support_version');
        add_option('cloudtart_support_version', CLOUDTART_SUPPORT_VERSION, '', 'yes');

        if ($this->logger) {
            $this->logger->log(sprintf(__('Plugin upgraded to version %s.', 'cloudtart-support'), CLOUDTART_SUPPORT_VERSION), 'info');
        }
    }

    /**
     * اگر کانکتور روی سایت نصب ولی غیرفعال است و سایت قبلاً به پلتفرم متصل بوده
     * (کلید API دارد)، کانکتور را فعال می‌کند تا اتصال پس از این ارتقا قطع نشود.
     */
    private function maybe_activate_connector() {
        if (!defined('CLOUDTART_SUPPORT_CONNECTOR_BASENAME')) {
            return;
        }

        $connector_file = WP_PLUGIN_DIR . '/' . CLOUDTART_SUPPORT_CONNECTOR_BASENAME;
        if (!file_exists($connector_file)) {
            return;
        }

        $legacy_key = isset($this->settings['api_key']) ? trim((string) $this->settings['api_key']) : '';
        $connector_settings = get_option('cloudtart_connector_settings', []);
        $connector_key = isset($connector_settings['api_key']) ? trim((string) $connector_settings['api_key']) : '';
        if ($legacy_key === '' && $connector_key === '') {
            return;
        }

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active(CLOUDTART_SUPPORT_CONNECTOR_BASENAME)) {
            return;
        }

        $result = activate_plugin(CLOUDTART_SUPPORT_CONNECTOR_BASENAME);
        if ($this->logger) {
            $this->logger->log(is_wp_error($result)
                ? sprintf(__('Could not activate the CloudTart Support Connector: %s', 'cloudtart-support'), $result->get_error_message())
                : __('CloudTart Support Connector was activated automatically.', 'cloudtart-support'),
                is_wp_error($result) ? 'error' : 'info');
        }
    }

    /**
     * آیا این سایت قبلاً به پلتفرم پشتیبانی متصل بوده (کلید API دارد)؟
     */
    private function site_has_support_key() {
        $legacy_key = isset($this->settings['api_key']) ? trim((string) $this->settings['api_key']) : '';
        $connector_settings = get_option('cloudtart_connector_settings', []);
        $connector_key = is_array($connector_settings) && isset($connector_settings['api_key']) ? trim((string) $connector_settings['api_key']) : '';
        return $legacy_key !== '' || $connector_key !== '';
    }

    /**
     * اگر سایت کلید پشتیبانی دارد ولی کانکتور اصلاً نصب نیست (مثلاً پلاگین اصلی از
     * طریق بروزرسانی وردپرس زودتر از کانکتور رسیده)، نصب کانکتور در پس‌زمینه
     * زمان‌بندی می‌شود تا ارتباط سایت با پلتفرم قطع نماند.
     */
    private function maybe_schedule_connector_install() {
        if (file_exists(WP_PLUGIN_DIR . '/' . CLOUDTART_SUPPORT_CONNECTOR_BASENAME) || !$this->site_has_support_key()) {
            return;
        }
        if (!wp_next_scheduled('cloudtart_support_install_connector')) {
            wp_schedule_single_event(time() + 30, 'cloudtart_support_install_connector');
        }
    }

    /**
     * نصب کانکتور از همان سرور اعلان بروزرسانی (update-api.php).
     *
     * @return true|WP_Error
     */
    public function install_connector() {
        if (!$this->site_has_support_key()) {
            return new WP_Error('no_key', __('This site has no CloudTart support API key.', 'cloudtart-support'));
        }

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!file_exists(WP_PLUGIN_DIR . '/' . CLOUDTART_SUPPORT_CONNECTOR_BASENAME)) {
            $response = wp_remote_post(cloudtart_support_update_api_url(), [
                'timeout' => 15,
                'body' => [
                    'action' => 'get_version',
                    'slug' => 'cloudtart-support-connector',
                    'version' => '0',
                    'url' => home_url(),
                ],
            ]);

            $data = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
            // نسخه‌ی قدیمی update-api.php پارامتر slug را نادیده می‌گیرد و بسته‌ی پلاگین اصلی را برمی‌گرداند.
            $is_connector = is_array($data) && isset($data['slug']) && $data['slug'] === 'cloudtart-support-connector';
            $package = $is_connector && !empty($data['download_url']) ? (string) $data['download_url'] : '';
            $host = strtolower((string) wp_parse_url($package, PHP_URL_HOST));

            // فقط بسته‌ای از دامنه‌ی کلادتارت و روی HTTPS نصب می‌شود.
            if ($package === '' || stripos($package, 'https://') !== 0 || !($host === 'cloudtart.com' || substr($host, -14) === '.cloudtart.com')) {
                $error = new WP_Error('no_package', __('The connector package address could not be read from the update server.', 'cloudtart-support'));
                $this->log_connector_install($error);
                return $error;
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $installed = $upgrader->install($package);

            if (is_wp_error($installed) || !$installed) {
                $error = is_wp_error($installed) ? $installed : new WP_Error('install_failed', __('Installing the connector failed (the host may require FTP credentials for plugin installs).', 'cloudtart-support'));
                $this->log_connector_install($error);
                return $error;
            }
        }

        if (!is_plugin_active(CLOUDTART_SUPPORT_CONNECTOR_BASENAME)) {
            $activated = activate_plugin(CLOUDTART_SUPPORT_CONNECTOR_BASENAME);
            if (is_wp_error($activated)) {
                $this->log_connector_install($activated);
                return $activated;
            }
        }

        $this->log_connector_install(true);
        return true;
    }

    private function log_connector_install($result) {
        if (!$this->logger) {
            return;
        }
        if (is_wp_error($result)) {
            $this->logger->log(sprintf(__('Could not install the CloudTart Support Connector: %s', 'cloudtart-support'), $result->get_error_message()), 'error');
        } else {
            $this->logger->log(__('CloudTart Support Connector was installed and activated automatically.', 'cloudtart-support'), 'info');
        }
    }

    /**
     * دکمه‌ی «نصب کانکتور» در اعلان صفحه‌ی تنظیمات.
     */
    public function handle_install_connector_request() {
        if (!current_user_can('install_plugins') || !current_user_can('activate_plugins')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'cloudtart-support'), 403);
        }
        check_admin_referer('cloudtart_install_connector');

        $result = $this->install_connector();

        wp_safe_redirect(add_query_arg(
            [
                'page' => 'cloudtart-support',
                'tab' => 'general',
                'cloudtart_connector_install' => is_wp_error($result) ? 'failed' : 'ok',
            ],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * فعال‌سازی پلاگین
     */
    public function activate_plugin() {
        // ایجاد دایرکتوری لاگ‌ها
        $log_dir_info = function_exists('cloudtart_support_get_log_dir')
            ? cloudtart_support_get_log_dir()
            : ['basedir' => CLOUDTART_SUPPORT_DIR . 'logs'];
        $log_dir = $log_dir_info['basedir'];
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
            // ایجاد فایل index.php برای جلوگیری از لیست دایرکتوری
            $index_file = $log_dir . '/index.php';
            if (!file_exists($index_file)) {
                file_put_contents($index_file, "<?php\n// Silence is golden.");
            }
        }

        // برنامه‌ریزی کرون‌ها
        if (!wp_next_scheduled('cloudtart_check_site_status')) {
            wp_schedule_event(time(), 'hourly', 'cloudtart_check_site_status');
        }

        if (!wp_next_scheduled('cloudtart_check_expiry_dates')) {
            wp_schedule_event(time(), 'daily', 'cloudtart_check_expiry_dates');
        }

        // ثبت لاگ فعال‌سازی
        if ($this->logger) {
            $this->logger->log(__('CloudTart support plugin activated.', 'cloudtart-support'), 'info');
        }
    }

    /**
     * غیرفعال‌سازی پلاگین
     */
    public function deactivate_plugin() {
        // حذف کرون‌ها (کرون ارسال وضعیت متعلق به نسخه‌های قدیمی هم پاک می‌شود)
        foreach (['cloudtart_check_site_status', 'cloudtart_send_site_status', 'cloudtart_check_expiry_dates'] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }

        // ثبت لاگ غیرفعال‌سازی
        if ($this->logger) {
            $this->logger->log(__('CloudTart support plugin deactivated.', 'cloudtart-support'), 'info');
        }
    }

    /**
     * ثبت و گزارش خطاهای فاتال PHP
     */
    public function catch_fatal_error() {
        $this->monitoring->catch_fatal_error();
    }

    /**
     * دسترسی به کلاس لاگر
     */
    public function get_logger() {
        return $this->logger;
    }

    /**
     * دسترسی به کلاس مانیتورینگ
     */
    public function get_monitoring() {
        return $this->monitoring;
    }

    /**
     * دسترسی به کلاس مدیریت تاریخ‌های انقضا
     */
    public function get_expiry_manager() {
        return $this->expiry_manager;
    }

    /**
     * تنظیمات عمومی پلاگین
     */
    public function get_settings() {
        return $this->settings;
    }
}
