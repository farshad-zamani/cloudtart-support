<?php
/**
 * کلاس مدیریت آپدیت پلاگین
 */

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

class CloudTart_Support_Updater {
    private $api_url;
    private $plugin_slug;
    private $plugin_file;
    private $current_version;
    private $plugin_data;
    private $active;
    private $license;
    private $plugin_name;
    private $notice_cache_key = 'cloudtart_support_remote_admin_notice';
    private $notice_cache_ttl = 21600; // 6 hours

    /** پاسخ get_version سرور آپدیت کش می‌شود (خطا کوتاه‌تر). */
    const VERSION_CACHE_KEY = 'cloudtart_support_update_info';
    const VERSION_CACHE_TTL = 21600; // 6 hours
    const VERSION_FAIL_TTL = 1800;   // 30 minutes
    const INFO_CACHE_KEY = 'cloudtart_support_plugin_info';

    /**
     * سازنده کلاس
     */
    public function __construct() {
        // امکان پیکربندی آدرس API آپدیت از طریق ثابت یا فیلتر
        $this->api_url = cloudtart_support_update_api_url();
        $this->plugin_slug = 'cloudtart-support';
        // استفاده از مقدار واقعی basename که وردپرس برای کلیدها و تصمیم‌گیری‌ها استفاده می‌کند
        $this->plugin_file = defined('CLOUDTART_SUPPORT_BASENAME') ? CLOUDTART_SUPPORT_BASENAME : 'cloudtart-support/cloudtart-support.php';

        // نسخه از ثابت خوانده می‌شود؛ هدر کامل فایل فقط وقتی لازم شد (صفحه‌ی جزئیات) خوانده می‌شود.
        $this->current_version = defined('CLOUDTART_SUPPORT_VERSION') ? CLOUDTART_SUPPORT_VERSION : '0';
        $this->license = 'valid'; // فرض می‌کنیم لایسنس معتبر است
        
        // افزودن فیلترهای آپدیت
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_pre_install', [$this, 'before_install'], 10, 2);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
        
        // نمایش پیام اضافی در جدول پلاگین‌ها - فقط پشتیبانی و تنظیمات
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
        
        // اضافه کردن امکان فعال/غیرفعال کردن بروزرسانی خودکار
        add_filter('auto_update_plugin', [$this, 'auto_update'], 10, 2);
        
        // اضافه کردن فیلد تنظیمات
        add_filter('plugin_action_links_' . $this->plugin_file, [$this, 'plugin_action_links']);

        // نوتیف از راه دور (مدیریت پیام‌های ادمین از طریق update-api)
        add_action('admin_notices', [$this, 'render_remote_admin_notice']);
        add_action('wp_ajax_cloudtart_support_dismiss_notice', [$this, 'ajax_dismiss_notice']);
        add_action('upgrader_process_complete', [$this, 'flush_version_cache'], 10, 2);
    }

    /**
     * هدر پلاگین (نام، توضیح، نویسنده و ...) فقط در صورت نیاز خوانده می‌شود.
     */
    private function plugin_data() {
        if ($this->plugin_data === null) {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $this->plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin_file, false, false);
            $this->plugin_name = $this->plugin_data['Name'];
        }
        return $this->plugin_data;
    }

    /**
     * اطلاعات آخرین نسخه از سرور آپدیت، با کش.
     *
     * وردپرس فیلتر pre_set_site_transient_update_plugins را در هر ذخیره‌ی transient
     * (چند بار در روز و پس از هر نصب/فعال‌سازی افزونه) صدا می‌زند. بدون کش، هر بار یک
     * درخواست ۱۰ ثانیه‌ای به سرور زده می‌شد و اگر cloudtart.com در دسترس نبود، صفحات
     * پیشخوان چند ثانیه معطل می‌ماندند.
     *
     * @return array
     */
    private function get_version_info() {
        // update-core.php?force-check=1 همیشه از سرور می‌پرسد.
        $force = is_admin() && isset($_GET['force-check']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$force) {
            $cached = get_site_transient(self::VERSION_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = wp_remote_post($this->api_url, [
            'timeout' => 10,
            'body' => [
                'action' => 'get_version',
                'slug' => $this->plugin_slug,
                'version' => $this->current_version,
                'license' => $this->license,
                'url' => home_url()
            ]
        ]);

        $data = null;
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            // پاسخ باید متعلق به همین پلاگین باشد (update-api.php هر دو افزونه را سرویس می‌دهد).
            if (is_array($decoded) && !empty($decoded['version']) && (!isset($decoded['slug']) || $decoded['slug'] === $this->plugin_slug)) {
                $data = $decoded;
            }
        }

        set_site_transient(self::VERSION_CACHE_KEY, $data ?: [], $data ? self::VERSION_CACHE_TTL : self::VERSION_FAIL_TTL);

        if ($data) {
            // برای دیباگ (بدون autoload؛ روی هر بازدید بارگذاری نمی‌شود)
            update_option('cloudtart_updater_response', [
                'time' => time(),
                'data' => $data,
                'current_version' => $this->current_version
            ], false);
        }

        return $data ?: [];
    }

    /**
     * پاسخ action=info سرور آپدیت، با کش.
     *
     * از وردپرس 6.5 کلاس WP_Plugin_Dependencies در هر بار باز شدن صفحه‌ی «افزونه‌ها» برای
     * وابستگی کانکتور (Requires Plugins: cloudtart-support) همین اطلاعات را می‌خواهد و خطا را
     * کش نمی‌کند؛ بدون این کش، هر بار که cloudtart.com در دسترس نبود صفحه ۱۵ ثانیه معطل می‌ماند.
     *
     * @return array
     */
    private function get_plugin_info_data() {
        // پنجره‌ی «نمایش جزئیات» همیشه اطلاعات تازه می‌خواهد.
        $force = is_admin() && isset($_GET['tab']) && $_GET['tab'] === 'plugin-information'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$force) {
            $cached = get_site_transient(self::INFO_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = wp_remote_post($this->api_url, [
            'timeout' => 15,
            'body' => [
                'action' => 'info',
                'slug' => $this->plugin_slug,
                'version' => $this->current_version,
                'license' => $this->license,
                'url' => home_url()
            ]
        ]);

        $data = [];
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($decoded) && $decoded && (!isset($decoded['slug']) || $decoded['slug'] === $this->plugin_slug)) {
                $data = $decoded;
            }
        }

        set_site_transient(self::INFO_CACHE_KEY, $data, $data ? self::VERSION_CACHE_TTL : self::VERSION_FAIL_TTL);

        return $data;
    }

    public function flush_version_cache($upgrader = null, $options = []) {
        if (is_array($options) && isset($options['type']) && $options['type'] === 'plugin') {
            delete_site_transient(self::VERSION_CACHE_KEY);
            delete_site_transient(self::INFO_CACHE_KEY);
        }
    }

    /**
     * بررسی وجود آپدیت جدید
     */
    public function check_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        // اطمینان از اینکه پلاگین ما در لیست بررسی وجود دارد
        if (!isset($transient->checked[$this->plugin_file])) {
            $transient->checked[$this->plugin_file] = $this->current_version;
        }

        $data = $this->get_version_info();

        if (empty($data)) {
            return $transient;
        }

        $plugin_data = $this->plugin_data();

        if (isset($data['version']) && version_compare($this->current_version, $data['version'], '<')) {
            // نسخه جدید موجود است
            $item = new stdClass();
            $item->slug = $this->plugin_slug;
            $item->plugin = $this->plugin_file;
            $item->new_version = $data['version'];
            $item->url = isset($data['homepage']) ? $data['homepage'] : $plugin_data['PluginURI'];
            $item->package = $data['download_url'];
            $item->tested = isset($data['tested']) ? $data['tested'] : '';
            $item->requires = isset($data['requires']) ? $data['requires'] : '';
            $item->icons = isset($data['icons']) ? $data['icons'] : [
                'default' => 'https://ps.w.org/hello-dolly/assets/icon-128x128.jpg'
            ];
            $item->banners = isset($data['banners']) ? $data['banners'] : [
                'default' => 'https://ps.w.org/hello-dolly/assets/banner-772x250.jpg'
            ];
            
            // افزودن اطلاعات آپدیت به transient
            $transient->response[$this->plugin_file] = $item;
        } else {
            // اگر آپدیتی وجود ندارد، اطلاعات را در no_update قرار می‌دهیم
            $item = new stdClass();
            $item->slug = $this->plugin_slug;
            $item->plugin = $this->plugin_file;
            $item->new_version = $this->current_version;
            $item->url = $plugin_data['PluginURI'];
            $item->package = '';
            $item->tested = '';
            $item->requires = '';
            
            $transient->no_update[$this->plugin_file] = $item;
        }
        
        return $transient;
    }
    
    /**
     * دریافت اطلاعات کامل پلاگین برای صفحه جزئیات آپدیت
     */
    public function plugin_info($result, $action, $args) {
        // بررسی کنیم که درخواست برای پلاگین ما است
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }
        
        $data = $this->get_plugin_info_data();

        // WP_Error (نه false) تا وردپرس برای این slug سراغ api.wordpress.org نرود.
        if (!$data) {
            return new WP_Error('plugins_api_failed', __('Plugin information could not be retrieved from the CloudTart update server. Please try again later.', 'cloudtart-support'));
        }

        // ایجاد شیء پاسخ
        $result = new stdClass();
        $this->plugin_data();

        // اطلاعات پایه
        // نام همیشه از هدر خود پلاگین خوانده می‌شود تا همه‌ی صفحات وردپرس (از جمله
        // «نیازمندی‌ها» در پلاگین کانکتور) نام را یکسان نمایش دهند.
        $result->name = $this->plugin_data['Name'];
        $result->slug = $this->plugin_slug;
        $result->version = isset($data['version']) ? $data['version'] : '';
        $result->tested = isset($data['tested']) ? $data['tested'] : '';
        $result->requires = isset($data['requires']) ? $data['requires'] : '';
        $result->author = isset($data['author']) ? $data['author'] : $this->plugin_data['Author'];
        $result->author_profile = isset($data['author_profile']) ? $data['author_profile'] : '';
        $result->download_link = isset($data['download_url']) ? $data['download_url'] : '';
        $result->trunk = isset($data['download_url']) ? $data['download_url'] : '';
        $result->last_updated = isset($data['last_updated']) ? $data['last_updated'] : '';
        $result->homepage = isset($data['homepage']) ? $data['homepage'] : '';
        $result->sections = isset($data['sections']) ? $data['sections'] : [
            'description' => isset($this->plugin_data['Description']) ? $this->plugin_data['Description'] : '',
            'changelog' => __('Changelog information is not available.', 'cloudtart-support')
        ];
        $result->banners = isset($data['banners']) ? $data['banners'] : [];
        $result->icons = isset($data['icons']) ? $data['icons'] : [];
        
        return $result;
    }

    public function before_install($response, $hook_extra) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $response;
        }

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        global $wp_filesystem;
        WP_Filesystem();

        if (!is_object($wp_filesystem)) {
            return $response;
        }

        $plugin_dir = rtrim(WP_PLUGIN_DIR . '/' . dirname($this->plugin_file), '/');
        $legacy_logs_dir = $plugin_dir . '/logs';

        if ($wp_filesystem->is_dir($legacy_logs_dir)) {
            $wp_filesystem->delete($legacy_logs_dir, true);
        }

        return $response;
    }
    
    /**
     * اقدامات پس از نصب آپدیت
     */
    public function after_install($response, $hook_extra, $result) {
        // فقط برای پلاگین ما
        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $this->plugin_file) {
            global $wp_filesystem;
            $plugin_dir    = rtrim(WP_PLUGIN_DIR . '/' . dirname($this->plugin_file), '/');
            $destination   = isset($result['destination']) ? rtrim($result['destination'], '/') : '';

            // اگر مقصد نهایی برابر پوشه پلاگین است، نیازی به جابجایی نیست
            if ($destination && $destination !== $plugin_dir) {
                // اگر مقصد داخل پوشه پلاگین باشد (نصب پوشه تو در تو)، محتوا را به بالا منتقل کنیم
                if (strpos($destination, $plugin_dir) === 0) {
                    $files = $wp_filesystem->dirlist($destination);
                    if (is_array($files)) {
                        foreach ($files as $name => $info) {
                            // جابجایی با اجازه overwrite
                            $wp_filesystem->move($destination . '/' . $name, $plugin_dir . '/' . $name, true);
                        }
                    }
                    // حذف پوشه مقصد تو در تو پس از انتقال
                    $wp_filesystem->delete($destination, true);
                } else {
                    // در غیر اینصورت کل پوشه را به مسیر پلاگین منتقل کنیم
                    $wp_filesystem->move($destination, $plugin_dir, true);
                }

                // بروز رسانی مقصد در نتیجه
                $result['destination'] = $plugin_dir;
            }

            // پاک کردن transient آپدیت‌ها تا وضعیت به‌روز شود
            delete_site_transient('update_plugins');

            // فعال‌سازی مجدد پلاگین (در صورت نیاز)
            if (is_plugin_inactive($this->plugin_file)) {
                activate_plugin($this->plugin_file);
            }

            // ذخیره اطلاعات دیباگ برای رهگیری فرآیند نصب
            update_option('cloudtart_updater_post_install', [
                'time' => time(),
                'plugin' => $this->plugin_file,
                'destination' => $result['destination'],
                'expected_dir' => $plugin_dir
            ]);
        }

        return $result;
    }
    
    /**
     * اضافه کردن لینک‌های اضافی به جدول پلاگین‌ها
     */
    public function plugin_row_meta($links, $file) {
        if ($file === $this->plugin_file) {
            $links[] = '<a href="' . admin_url('options-general.php?page=cloudtart-support') . '">' . esc_html__('Settings', 'cloudtart-support') . '</a>';
            $links[] = '<a href="https://cloudtart.com/support" target="_blank">' . esc_html__('Support', 'cloudtart-support') . '</a>';
        }
        
        return $links;
    }
    
    /**
     * تعیین اینکه آیا پلاگین باید به صورت خودکار آپدیت شود یا خیر
     */
    public function auto_update($update, $item) {
        // مطابق استاندارد هسته وردپرس: هیچ تصمیمی را تحمیل نکنیم و
        // همان مقدار تصمیم‌گیری هسته را برگردانیم تا لینک‌های «فعال‌سازی/غیرفعال‌سازی بروزرسانی خودکار»
        // در صفحه افزونه‌ها به درستی کار کنند.
        if (isset($item->slug) && $item->slug === $this->plugin_slug) {
            return $update;
        }

        return $update;
    }
    
    /**
     * اضافه کردن لینک‌های عملیات به جدول پلاگین‌ها
     */
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=cloudtart-support') . '">' . esc_html__('Settings', 'cloudtart-support') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function render_remote_admin_notice() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $notice = $this->get_remote_admin_notice();
        if (empty($notice) || !is_array($notice) || empty($notice['id']) || empty($notice['message'])) {
            return;
        }

        $dismissed = get_user_meta(get_current_user_id(), 'cloudtart_support_dismissed_notices', true);
        if (!is_array($dismissed)) {
            $dismissed = [];
        }
        if (in_array($notice['id'], $dismissed, true)) {
            return;
        }

        $notice_class = 'notice-info';
        if (!empty($notice['type']) && in_array($notice['type'], ['info', 'success', 'warning', 'error'], true)) {
            $notice_class = 'notice-' . $notice['type'];
        }

        $message = wp_kses_post($notice['message']);
        $cta_html = '';
        if (!empty($notice['cta_url']) && !empty($notice['cta_text'])) {
            $cta_html = ' <a class="button button-small" style="margin-right:8px" target="_blank" rel="noopener noreferrer" href="'
                . esc_url($notice['cta_url']) . '">' . esc_html($notice['cta_text']) . '</a>';
        }
        ?>
        <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible cloudtart-remote-notice" data-notice-id="<?php echo esc_attr($notice['id']); ?>">
            <p><?php echo $message . $cta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
        </div>
        <script>
            jQuery(function ($) {
                $(document).off('click.cloudtartNoticeDismiss').on('click.cloudtartNoticeDismiss', '.cloudtart-remote-notice .notice-dismiss', function () {
                    var $notice = $(this).closest('.cloudtart-remote-notice');
                    var noticeId = $notice.data('notice-id');
                    if (!noticeId) {
                        return;
                    }
                    $.post(ajaxurl, {
                        action: 'cloudtart_support_dismiss_notice',
                        notice_id: noticeId,
                        nonce: '<?php echo esc_js(wp_create_nonce('cloudtart_support_dismiss_notice')); ?>'
                    });
                });
            });
        </script>
        <?php
    }

    public function ajax_dismiss_notice() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('cloudtart_support_dismiss_notice', 'nonce');

        $notice_id = isset($_POST['notice_id']) ? sanitize_text_field(wp_unslash($_POST['notice_id'])) : '';
        if ($notice_id === '') {
            wp_send_json_error(['message' => 'invalid_notice_id'], 400);
        }

        $user_id = get_current_user_id();
        $dismissed = get_user_meta($user_id, 'cloudtart_support_dismissed_notices', true);
        if (!is_array($dismissed)) {
            $dismissed = [];
        }

        if (!in_array($notice_id, $dismissed, true)) {
            $dismissed[] = $notice_id;
            update_user_meta($user_id, 'cloudtart_support_dismissed_notices', $dismissed);
        }

        wp_send_json_success(['dismissed' => true]);
    }

    private function get_remote_admin_notice() {
        $cached = get_transient($this->notice_cache_key);
        if ($cached !== false) {
            return is_array($cached) ? $cached : [];
        }

        // این درخواست داخل رندر صفحه‌ی پیشخوان است؛ اگر سرور در دسترس نباشد نباید صفحه را معطل کند.
        $response = wp_remote_post($this->api_url, [
            'timeout' => 5,
            'body' => [
                'action' => 'get_admin_notice',
                'slug' => $this->plugin_slug,
                'version' => $this->current_version,
                'url' => home_url()
            ]
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($this->notice_cache_key, [], HOUR_IN_SECONDS);
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            set_transient($this->notice_cache_key, [], HOUR_IN_SECONDS);
            return [];
        }

        $notice = [];
        if (!empty($data['notice']) && is_array($data['notice'])) {
            $notice = $data['notice'];
        } elseif (!empty($data['id']) && !empty($data['message'])) {
            $notice = $data;
        }

        if (empty($notice['id']) || empty($notice['message'])) {
            set_transient($this->notice_cache_key, [], HOUR_IN_SECONDS);
            return [];
        }

        if (isset($notice['enabled']) && !$notice['enabled']) {
            set_transient($this->notice_cache_key, [], HOUR_IN_SECONDS);
            return [];
        }

        $normalized = [
            'id' => sanitize_key((string) $notice['id']),
            'type' => isset($notice['type']) ? sanitize_key((string) $notice['type']) : 'info',
            'message' => wp_kses_post((string) $notice['message']),
            'cta_url' => isset($notice['cta_url']) ? esc_url_raw((string) $notice['cta_url']) : '',
            'cta_text' => isset($notice['cta_text']) ? sanitize_text_field((string) $notice['cta_text']) : '',
        ];

        if ($normalized['id'] === '' || $normalized['message'] === '') {
            set_transient($this->notice_cache_key, [], HOUR_IN_SECONDS);
            return [];
        }

        set_transient($this->notice_cache_key, $normalized, $this->notice_cache_ttl);

        return $normalized;
    }
}
