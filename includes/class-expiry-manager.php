<?php
/**
 * کلاس مدیریت تاریخ‌های انقضا و دامنه‌های چندگانه
 */

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

class CloudTart_Support_Expiry_Manager {
    private $logger;
    private $settings;
    private $db_table;
    private $db_version = '1.1'; // افزایش نسخه برای اضافه شدن فیلد جدید

    /**
     * سازنده کلاس
     */
    public function __construct($logger) {
        global $wpdb;
        $this->logger = $logger;
        $this->settings = get_option('cloudtart_expiry_settings', [
            'domain_expiry' => '',
            'domain_period' => '1',
            'hosting_expiry' => '',
            'hosting_period' => '1',
            'notification_emails' => '',
            'notification_days' => '10'
        ]);
        
        // تنظیم نام جدول دیتابیس
        $this->db_table = $wpdb->prefix . 'cloudtart_domains';
        
        // بررسی ایجاد جدول دیتابیس
        $this->maybe_create_db_table();
        
        // راه‌اندازی هوک‌ها
        $this->setup_hooks();
    }

    /**
     * ایجاد جدول دیتابیس اگر وجود نداشته باشد
     */
    private function maybe_create_db_table() {
        global $wpdb;

        $table_name = $this->db_table;
        $current_version = get_option('cloudtart_db_version', '1.0');

        // قبلاً روی هر بازدید سایت یک کوئری SHOW TABLES اجرا می‌شد. حالا نسخه‌ی جدول از
        // option (که در کش است) خوانده می‌شود و وجود جدول فقط در پیشخوان و روزی یک بار
        // دوباره بررسی می‌شود.
        $needs_upgrade = version_compare((string) $current_version, $this->db_version, '<');
        $table_exists = true;

        if (!$needs_upgrade) {
            if (!is_admin() || wp_doing_ajax() || get_transient('cloudtart_domains_table_ok')) {
                $this->migrate_domains_to_db();
                return;
            }
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) === $table_name;
            if ($table_exists) {
                set_transient('cloudtart_domains_table_ok', 1, DAY_IN_SECONDS);
            }
        } else {
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) === $table_name;
        }

        if (!$table_exists || $needs_upgrade) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                domain_name varchar(100) NOT NULL,
                domain_expiry date NOT NULL,
                date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                renewal_period int(2) DEFAULT 1 NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY domain_name (domain_name)
            ) $charset_collate;";
            
            dbDelta($sql);
            
            // ذخیره نسخه دیتابیس جدید
            update_option('cloudtart_db_version', $this->db_version);
            
            // لاگ کردن ایجاد جدول یا بروزرسانی
            if ($table_exists) {
                if ($this->logger) {
                    $this->logger->log(sprintf(__('Domains database table updated: %1$s to version %2$s', 'cloudtart-support'), $table_name, $this->db_version), 'info');
                }
            } else {
                if ($this->logger) {
                    $this->logger->log(sprintf(__('Domains database table created: %s', 'cloudtart-support'), $table_name), 'info');
                }
            }
        }
        
        // مهاجرت داده‌های قبلی از option به جدول دیتابیس
        $this->migrate_domains_to_db();
    }
    
    /**
     * مهاجرت داده‌های دامنه از option به دیتابیس
     */
    private function migrate_domains_to_db() {
        global $wpdb;
        
        // دریافت داده‌های قبلی
        $old_settings = get_option('cloudtart_expiry_settings', []);
        
        // بررسی وجود داده‌های قبلی دامنه‌ها و مهاجرت
        if (isset($old_settings['multiple_domains']) && is_array($old_settings['multiple_domains']) && !empty($old_settings['multiple_domains'])) {
            foreach ($old_settings['multiple_domains'] as $domain) {
                if (!isset($domain['name']) || !isset($domain['expiry'])) {
                    continue;
                }
                
                // بررسی تکراری نبودن
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->db_table} WHERE domain_name = %s",
                    $domain['name']
                ));
                
                if ($exists) {
                    continue;
                }
                
                // دوره تمدید پیش‌فرض
                $renewal_period = isset($domain['period']) ? (int)$domain['period'] : 1;
                
                // درج در دیتابیس
                $wpdb->insert(
                    $this->db_table,
                    [
                        'domain_name' => $domain['name'],
                        'domain_expiry' => $domain['expiry'],
                        'date_added' => current_time('mysql'),
                        'renewal_period' => $renewal_period
                    ],
                    ['%s', '%s', '%s', '%d']
                );
            }
            
            // حذف داده‌های قدیمی از option
            unset($old_settings['multiple_domains']);
            update_option('cloudtart_expiry_settings', $old_settings);
            
            // لاگ
            if ($this->logger) {
                $this->logger->log(__('Domain data migrated successfully to the new database.', 'cloudtart-support'), 'info');
            }
        }
    }
    
    /**
     * متد استاتیک برای فعال‌سازی پلاگین
     */
    public static function activate() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cloudtart_domains';
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            domain_name varchar(100) NOT NULL,
            domain_expiry date NOT NULL,
            date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            renewal_period int(2) DEFAULT 1 NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY domain_name (domain_name)
        ) $charset_collate;";
        
        dbDelta($sql);
        add_option('cloudtart_db_version', '1.1');
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function setup_hooks() {
        // اضافه کردن هوک برای بررسی تاریخ‌های انقضا
        add_action('cloudtart_check_expiry_dates', [$this, 'check_expiry_dates']);
        
        // نمایش اعلان‌ها در پنل ادمین
        add_action('admin_notices', [$this, 'display_expiry_notices']);
        
        // اضافه کردن AJAX برای مدیریت دامنه‌ها
        add_action('wp_ajax_cloudtart_add_domain', [$this, 'ajax_add_domain']);
        add_action('wp_ajax_cloudtart_remove_domain', [$this, 'ajax_remove_domain']);
        
        // اضافه کردن AJAX برای "تمدید کردم"
        add_action('wp_ajax_cloudtart_renew_domain', [$this, 'ajax_renew_domain']);
        add_action('wp_ajax_cloudtart_renew_main_domain', [$this, 'ajax_renew_main_domain']);
        add_action('wp_ajax_cloudtart_renew_hosting', [$this, 'ajax_renew_hosting']);
        
        // اضافه کردن اسکریپت در ادمین
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    /**
     * اضافه کردن اسکریپت‌های مورد نیاز
     */
    public function enqueue_scripts($hook) {
        // اضافه کردن jQuery برای همه صفحات ادمین
        wp_enqueue_script('jquery');

        $script = '
            jQuery(document).ready(function($) {
                function handleRenew($btn, data, confirmMessage) {
                    if (!confirm(confirmMessage)) {
                        return;
                    }

                    var originalLabel = $btn.text();
                    $btn.prop("disabled", true).text("' . esc_js(__('Applying...', 'cloudtart-support')) . '");

                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: data,
                        success: function(response) {
                            if (response.success) {
                                var $notice = $btn.closest(".notice");
                                var newExpiry = response.data && response.data.new_expiry ? response.data.new_expiry : "";
                                var message = response.data && response.data.message ? response.data.message : "' . esc_js(__('Renewal recorded.', 'cloudtart-support')) . '";
                                if (newExpiry) {
                                    message += " " + "' . esc_js(__('New expiry date:', 'cloudtart-support')) . '" + " " + newExpiry;
                                }

                                $notice.removeClass("notice-warning notice-error").addClass("notice-success");
                                $notice.find("p").first().html($("<div/>").text(message).html());
                                $notice.find(".cloudtart-renew-btn, .cloudtart-renew-link").remove();
                                window.setTimeout(function() { $notice.fadeOut(); }, 5000);
                            } else {
                                $btn.prop("disabled", false).text(originalLabel);
                                var err = (response.data && response.data.message) ? response.data.message : "";
                                alert("' . esc_js(__('Error: ', 'cloudtart-support')) . '" + err);
                            }
                        },
                        error: function() {
                            $btn.prop("disabled", false).text(originalLabel);
                            alert("' . esc_js(__('Error connecting to server.', 'cloudtart-support')) . '");
                        }
                    });
                }

                $(document).on("click", ".renew-main-domain-btn", function() {
                    handleRenew($(this), {
                        action: "cloudtart_renew_main_domain",
                        nonce: "' . wp_create_nonce('cloudtart_support_nonce') . '"
                    }, "' . esc_js(__('Confirm that the domain has been renewed. The next expiry date will be auto-calculated from the saved renewal period. Continue?', 'cloudtart-support')) . '");
                });

                $(document).on("click", ".renew-hosting-btn", function() {
                    handleRenew($(this), {
                        action: "cloudtart_renew_hosting",
                        nonce: "' . wp_create_nonce('cloudtart_support_nonce') . '"
                    }, "' . esc_js(__('Confirm that the hosting has been renewed. The next expiry date will be auto-calculated from the saved renewal period. Continue?', 'cloudtart-support')) . '");
                });

                $(document).on("click", ".renew-domain-btn", function() {
                    var $btn = $(this);
                    handleRenew($btn, {
                        action: "cloudtart_renew_domain",
                        nonce: "' . wp_create_nonce('cloudtart_support_nonce') . '",
                        domain_id: $btn.data("domain-id")
                    }, "' . esc_js(__('Confirm that this domain has been renewed. The next expiry date will be auto-calculated from the saved renewal period. Continue?', 'cloudtart-support')) . '");
                });
            });
        ';
        wp_add_inline_script('jquery', $script);
    }

    /**
     * افزودن دامنه جدید - AJAX Handler
     */
    public function ajax_add_domain() {
        try {
            check_ajax_referer('cloudtart_support_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'cloudtart-support'));
            }
            
            $domain_name = isset($_POST['domain_name']) ? sanitize_text_field($_POST['domain_name']) : '';
            $domain_expiry = isset($_POST['domain_expiry']) ? sanitize_text_field($_POST['domain_expiry']) : '';
            $renewal_period = isset($_POST['renewal_period']) ? intval($_POST['renewal_period']) : 1;
            
            if (empty($domain_name) || empty($domain_expiry)) {
                throw new Exception(__('Domain name and expiry date are required.', 'cloudtart-support'));
            }
            
            // بررسی معتبر بودن دامنه (فقط بررسی ساده فرمت)
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*\.[a-z]{2,}$/i', $domain_name) && 
                !filter_var($domain_name, FILTER_VALIDATE_IP)) {
                throw new Exception(__('Invalid domain name format.', 'cloudtart-support'));
            }
            
            global $wpdb;
            
            // بررسی تکراری نبودن دامنه
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->db_table} WHERE domain_name = %s",
                $domain_name
            ));
            
            if ($exists) {
                throw new Exception(__('This domain has already been added.', 'cloudtart-support'));
            }
            
            // افزودن دامنه به دیتابیس
            $result = $wpdb->insert(
                $this->db_table,
                [
                    'domain_name' => $domain_name,
                    'domain_expiry' => $domain_expiry,
                    'date_added' => current_time('mysql'),
                    'renewal_period' => $renewal_period
                ],
                ['%s', '%s', '%s', '%d']
            );
            
            if (!$result) {
                throw new Exception(__('Error saving domain.', 'cloudtart-support'));
            }
            
            // لاگ عملیات موفق
            if ($this->logger) {
                $this->logger->log(sprintf(__('New domain added: %1$s with expiry: %2$s and renewal period: %3$s years', 'cloudtart-support'), $domain_name, $domain_expiry, $renewal_period), 'success');
            }
            
            // دریافت لیست دامنه‌ها برای پاسخ
            $domains = $wpdb->get_results(
                "SELECT * FROM {$this->db_table} ORDER BY domain_expiry ASC",
                ARRAY_A
            );
            
            wp_send_json_success([
                'message' => __('Domain added successfully.', 'cloudtart-support'),
                'domains' => $domains
            ]);
        } catch (Exception $e) {
            // لاگ خطا
            if ($this->logger) {
                $this->logger->log(sprintf(__('Error adding domain: %s', 'cloudtart-support'), $e->getMessage()), 'error');
            }
            
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * حذف دامنه - AJAX Handler
     */
    public function ajax_remove_domain() {
        try {
            check_ajax_referer('cloudtart_support_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'cloudtart-support'));
            }
            
            $domain_index = isset($_POST['domain_index']) ? intval($_POST['domain_index']) : -1;
            
            if ($domain_index < 1) {
                throw new Exception(__('Invalid domain index.', 'cloudtart-support'));
            }
            
            global $wpdb;
            
            // دریافت اطلاعات دامنه قبل از حذف
            $domain = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->db_table} WHERE id = %d",
                $domain_index
            ));
            
            if (!$domain) {
                throw new Exception(__('Domain not found.', 'cloudtart-support'));
            }
            
            $removed_domain = $domain->domain_name;
            
            // حذف دامنه
            $result = $wpdb->delete(
                $this->db_table,
                ['id' => $domain_index],
                ['%d']
            );
            
            if (!$result) {
                throw new Exception(__('Error deleting domain.', 'cloudtart-support'));
            }
            
            // لاگ عملیات موفق
            if ($this->logger) {
                $this->logger->log(sprintf(__('Domain removed: %s', 'cloudtart-support'), $removed_domain), 'success');
            }
            
            wp_send_json_success([
                'message' => sprintf(__('Domain %s removed successfully.', 'cloudtart-support'), $removed_domain)
            ]);
        } catch (Exception $e) {
            // لاگ خطا
            if ($this->logger) {
                $this->logger->log(sprintf(__('Error removing domain: %s', 'cloudtart-support'), $e->getMessage()), 'error');
            }
            
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * تمدید دامنه اصلی - AJAX Handler
     */
    public function ajax_renew_main_domain() {
        try {
            check_ajax_referer('cloudtart_support_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'cloudtart-support'));
            }
            
            // دریافت تنظیمات فعلی
            $expiry_settings = get_option('cloudtart_expiry_settings', []);
            
            // بررسی وجود تاریخ انقضای دامنه و دوره تمدید
            if (empty($expiry_settings['domain_expiry']) || empty($expiry_settings['domain_period'])) {
                throw new Exception(__('Domain expiry date or renewal period settings not found.', 'cloudtart-support'));
            }
            
            // محاسبه تاریخ انقضای جدید
            $current_expiry = $expiry_settings['domain_expiry'];
            $period = max(1, (int)$expiry_settings['domain_period']);
            $new_expiry = $this->calculate_next_expiry_date($current_expiry, $period, 'domain');
            
            // بروزرسانی تاریخ انقضا
            $expiry_settings['domain_expiry'] = $new_expiry;
            update_option('cloudtart_expiry_settings', $expiry_settings);
            
            // لاگ عملیات
            if ($this->logger) {
                $this->logger->log(sprintf(__('Main domain renewed. New expiry date: %s', 'cloudtart-support'), $new_expiry), 'success');
            }
            
            wp_send_json_success([
                'message' => __('Main domain renewed successfully.', 'cloudtart-support'),
                'new_expiry' => $new_expiry
            ]);
            
        } catch (Exception $e) {
            // لاگ خطا
            if ($this->logger) {
                $this->logger->log(sprintf(__('Error renewing main domain: %s', 'cloudtart-support'), $e->getMessage()), 'error');
            }
            
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * تمدید هاست - AJAX Handler
     */
    public function ajax_renew_hosting() {
        try {
            check_ajax_referer('cloudtart_support_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'cloudtart-support'));
            }
            
            // دریافت تنظیمات فعلی
            $expiry_settings = get_option('cloudtart_expiry_settings', []);
            
            // بررسی وجود تاریخ انقضای هاست و دوره تمدید
            if (empty($expiry_settings['hosting_expiry']) || empty($expiry_settings['hosting_period'])) {
                throw new Exception(__('Hosting expiry date or renewal period settings not found.', 'cloudtart-support'));
            }
            
            // محاسبه تاریخ انقضای جدید
            $current_expiry = $expiry_settings['hosting_expiry'];
            $period = max(1, (int)$expiry_settings['hosting_period']);
            $new_expiry = $this->calculate_next_expiry_date($current_expiry, $period, 'hosting');
            
            // بروزرسانی تاریخ انقضا
            $expiry_settings['hosting_expiry'] = $new_expiry;
            update_option('cloudtart_expiry_settings', $expiry_settings);
            
            // لاگ عملیات
            if ($this->logger) {
                $this->logger->log(sprintf(__('Hosting renewed. New expiry date: %s', 'cloudtart-support'), $new_expiry), 'success');
            }
            
            wp_send_json_success([
                'message' => __('Hosting renewed successfully.', 'cloudtart-support'),
                'new_expiry' => $new_expiry
            ]);
            
        } catch (Exception $e) {
            // لاگ خطا
            if ($this->logger) {
                $this->logger->log(sprintf(__('Error renewing hosting: %s', 'cloudtart-support'), $e->getMessage()), 'error');
            }
            
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * تمدید دامنه‌های دیگر - AJAX Handler
     */
    public function ajax_renew_domain() {
        try {
            check_ajax_referer('cloudtart_support_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'cloudtart-support'));
            }
            
            $domain_id = isset($_POST['domain_id']) ? intval($_POST['domain_id']) : -1;
            
            if ($domain_id < 1) {
                throw new Exception(__('Invalid domain ID.', 'cloudtart-support'));
            }
            
            global $wpdb;
            
            // دریافت اطلاعات دامنه
            $domain = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->db_table} WHERE id = %d",
                $domain_id
            ));
            
            if (!$domain) {
                throw new Exception(__('Domain not found.', 'cloudtart-support'));
            }
            
            // استفاده از دوره تمدید ذخیره شده در دیتابیس، یا مقدار پیش‌فرض 1 سال
            $period = !empty($domain->renewal_period) ? max(1, (int)$domain->renewal_period) : 1;
            
            // محاسبه تاریخ انقضای جدید
            $current_expiry = $domain->domain_expiry;
            $new_expiry = $this->calculate_next_expiry_date($current_expiry, $period, 'domain');
            
            // بروزرسانی تاریخ انقضای دامنه
            $result = $wpdb->update(
                $this->db_table,
                ['domain_expiry' => $new_expiry],
                ['id' => $domain_id],
                ['%s'],
                ['%d']
            );
            
            if (!$result) {
                throw new Exception(__('Error updating domain expiry date.', 'cloudtart-support'));
            }
            
            // لاگ عملیات
            if ($this->logger) {
                $this->logger->log(sprintf(__('Domain %1$s renewed. New expiry date: %2$s', 'cloudtart-support'), $domain->domain_name, $new_expiry), 'success');
            }
            
            wp_send_json_success([
                'message' => sprintf(__('Domain %s renewed successfully.', 'cloudtart-support'), $domain->domain_name),
                'new_expiry' => $new_expiry
            ]);
            
        } catch (Exception $e) {
            // لاگ خطا
            if ($this->logger) {
                $this->logger->log(sprintf(__('Error renewing domain: %s', 'cloudtart-support'), $e->getMessage()), 'error');
            }
            
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * بررسی تاریخ‌های انقضا و ارسال اطلاع‌رسانی
     */
    public function check_expiry_dates() {
        $domain_expiry = isset($this->settings['domain_expiry']) ? $this->settings['domain_expiry'] : '';
        $hosting_expiry = isset($this->settings['hosting_expiry']) ? $this->settings['hosting_expiry'] : '';
        $notification_days = isset($this->settings['notification_days']) ? (int)$this->settings['notification_days'] : 10;
        $notification_emails = isset($this->settings['notification_emails']) ? $this->settings['notification_emails'] : '';
        
        // بررسی وجود داده‌های لازم
        if (empty($domain_expiry) && empty($hosting_expiry)) {
            return;
        }
        
        if (empty($notification_emails)) {
            $notification_emails = get_option('admin_email');
        }
        
        $site_url = home_url();
        $site_name = get_bloginfo('name');
        $emails = array_map('trim', explode(',', $notification_emails));
        $emails = array_slice($emails, 0, 5); // محدود به 5 آدرس ایمیل
        
        // بررسی تاریخ انقضای دامنه
        if (!empty($domain_expiry)) {
            $expiry_date = strtotime($domain_expiry);
            $days_remaining = ceil(($expiry_date - time()) / (24 * 60 * 60));
            
            if ($days_remaining <= $notification_days && $days_remaining > 0) {
                if ($this->logger) {
                $this->logger->log(sprintf(__('Warning: domain expiry is near. %s days remaining.', 'cloudtart-support'), $days_remaining), 'expiry');
                }
                
                // ارسال ایمیل اطلاع‌رسانی
            $subject = sprintf(__('Reminder: domain renewal for site %s is due soon', 'cloudtart-support'), parse_url($site_url, PHP_URL_HOST));
            $message = __('Hello,', 'cloudtart-support') . "\n\n";
            $message .= __('This email is a reminder to renew your site domain:', 'cloudtart-support') . "\n\n";
            $message .= sprintf(__('Site name: %s', 'cloudtart-support'), $site_name) . "\n";
            $message .= sprintf(__('Site URL: %s', 'cloudtart-support'), $site_url) . "\n";
            $message .= sprintf(__('Domain expiry date: %s', 'cloudtart-support'), $domain_expiry) . "\n";
            $message .= sprintf(__('Days remaining: %1$s days', 'cloudtart-support'), $days_remaining) . "\n\n";
            $message .= __('Please renew the domain as soon as possible.', 'cloudtart-support') . "\n\n";
            $message .= __('Thank you,', 'cloudtart-support') . "\n";
            $message .= __('CloudTart Support System', 'cloudtart-support');
                
                foreach ($emails as $email) {
                    $this->send_email($email, $subject, $message);
                }
            }
            
            if ($days_remaining <= 0) {
                if ($this->logger) {
                    $this->logger->log(sprintf(__('Error: domain expiry has passed. %s days overdue.', 'cloudtart-support'), abs($days_remaining)), 'error');
                }
            }
        }
        
        // بررسی تاریخ انقضای هاست
        if (!empty($hosting_expiry)) {
            $expiry_date = strtotime($hosting_expiry);
            $days_remaining = ceil(($expiry_date - time()) / (24 * 60 * 60));
            
            if ($days_remaining <= $notification_days && $days_remaining > 0) {
                if ($this->logger) {
                    $this->logger->log(sprintf(__('Warning: hosting expiry is near. %s days remaining.', 'cloudtart-support'), $days_remaining), 'expiry');
                }
                
                // ارسال ایمیل اطلاع‌رسانی
                $subject = sprintf(__('Reminder: hosting renewal for site %s is due soon', 'cloudtart-support'), parse_url($site_url, PHP_URL_HOST));
                $message = __('Hello,', 'cloudtart-support') . "\n\n";
                $message .= __('This email is a reminder to renew your site hosting:', 'cloudtart-support') . "\n\n";
                $message .= sprintf(__('Site name: %s', 'cloudtart-support'), $site_name) . "\n";
                $message .= sprintf(__('Site URL: %s', 'cloudtart-support'), $site_url) . "\n";
                $message .= sprintf(__('Hosting expiry date: %s', 'cloudtart-support'), $hosting_expiry) . "\n";
                $message .= sprintf(__('Days remaining: %1$s days', 'cloudtart-support'), $days_remaining) . "\n\n";
                $message .= __('Please renew the hosting as soon as possible.', 'cloudtart-support') . "\n\n";
                $message .= __('Thank you,', 'cloudtart-support') . "\n";
                $message .= __('CloudTart Support System', 'cloudtart-support');
                
                foreach ($emails as $email) {
                    $this->send_email($email, $subject, $message);
                }
            }
            
            if ($days_remaining <= 0) {
                if ($this->logger) {
                    $this->logger->log(sprintf(__('Error: hosting expiry has passed. %s days overdue.', 'cloudtart-support'), abs($days_remaining)), 'error');
                }
            }
        }
        
        // اضافه کردن بررسی دامنه‌های چندگانه از دیتابیس
        global $wpdb;
        
        $domains = $wpdb->get_results(
            "SELECT * FROM {$this->db_table}",
            ARRAY_A
        );
        
        foreach ((array) $domains as $domain) {
            $domain_name = $domain['domain_name'];
            $domain_expiry = $domain['domain_expiry'];
            
            if (!empty($domain_expiry)) {
                $expiry_date = strtotime($domain_expiry);
                $days_remaining = ceil(($expiry_date - time()) / (24 * 60 * 60));
                
                if ($days_remaining <= $notification_days && $days_remaining > 0) {
                    if ($this->logger) {
                        $this->logger->log(sprintf(__('Warning: domain %1$s expiry is near. %2$s days remaining.', 'cloudtart-support'), $domain_name, $days_remaining), 'expiry');
                    }
                    
                    // ارسال ایمیل اطلاع‌رسانی
                    $subject = sprintf(__('Reminder: domain %s renewal is due soon', 'cloudtart-support'), $domain_name);
                    $message = __('Hello,', 'cloudtart-support') . "\n\n";
                    $message .= __('This email is a reminder to renew the domain:', 'cloudtart-support') . "\n\n";
                    $message .= sprintf(__('Domain name: %s', 'cloudtart-support'), $domain_name) . "\n";
                    $message .= sprintf(__('Expiry date: %s', 'cloudtart-support'), $domain_expiry) . "\n";
                    $message .= sprintf(__('Days remaining: %1$s days', 'cloudtart-support'), $days_remaining) . "\n\n";
                    $message .= __('Please renew the domain as soon as possible.', 'cloudtart-support') . "\n\n";
                    $message .= __('Thank you,', 'cloudtart-support') . "\n";
                    $message .= __('CloudTart Support System', 'cloudtart-support');
                    
                    foreach ($emails as $email) {
                        $this->send_email($email, $subject, $message);
                    }
                }
                
                if ($days_remaining <= 0) {
                    if ($this->logger) {
                        $this->logger->log(sprintf(__('Error: domain %1$s expiry has passed. %2$s days overdue.', 'cloudtart-support'), $domain_name, abs($days_remaining)), 'error');
                    }
                }
            }
        }
    }
    
    /**
     * نمایش اعلان‌ها در پنل ادمین
     */
    public function display_expiry_notices() {
        // بررسی دسترسی کاربر
        if (!current_user_can('manage_options')) {
            return;
        }

        $domain_expiry = isset($this->settings['domain_expiry']) ? $this->settings['domain_expiry'] : '';
        $hosting_expiry = isset($this->settings['hosting_expiry']) ? $this->settings['hosting_expiry'] : '';
        $notification_days = isset($this->settings['notification_days']) ? (int)$this->settings['notification_days'] : 10;
        $settings_url = admin_url('options-general.php?page=cloudtart-support&tab=expiry');

        // بررسی تاریخ انقضای دامنه
        if (!empty($domain_expiry)) {
            $expiry_date = strtotime($domain_expiry);
            $days_remaining = ceil(($expiry_date - time()) / (24 * 60 * 60));

            if ($days_remaining <= $notification_days && $days_remaining > 0) {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <strong><?php echo esc_html__('Domain Renewal Warning:', 'cloudtart-support'); ?></strong>
                        <?php printf(esc_html__('%s days remaining until the site domain expires.', 'cloudtart-support'), esc_html($days_remaining)); ?>
                        <?php echo esc_html__('Please renew the domain as soon as possible.', 'cloudtart-support'); ?>
                        <button type="button" class="button renew-main-domain-btn cloudtart-renew-btn"><?php echo esc_html__('I have renewed it', 'cloudtart-support'); ?></button>
                        <a href="<?php echo esc_url($settings_url); ?>" class="cloudtart-renew-link"><?php echo esc_html__('Open expiry settings', 'cloudtart-support'); ?></a>
                    </p>
                </div>
                <?php
            } elseif ($days_remaining <= 0) {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p>
                        <strong><?php echo esc_html__('Alert:', 'cloudtart-support'); ?></strong>
                        <?php printf(esc_html__('%s days have passed since the site domain expired!', 'cloudtart-support'), esc_html(abs($days_remaining))); ?>
                        <?php echo esc_html__('Please renew the domain immediately.', 'cloudtart-support'); ?>
                        <button type="button" class="button renew-main-domain-btn cloudtart-renew-btn"><?php echo esc_html__('I have renewed it', 'cloudtart-support'); ?></button>
                        <a href="<?php echo esc_url($settings_url); ?>" class="cloudtart-renew-link"><?php echo esc_html__('Open expiry settings', 'cloudtart-support'); ?></a>
                    </p>
                </div>
                <?php
            }
        }

        // بررسی تاریخ انقضای هاست
        if (!empty($hosting_expiry)) {
            $expiry_date = strtotime($hosting_expiry);
            $days_remaining = ceil(($expiry_date - time()) / (24 * 60 * 60));

            if ($days_remaining <= $notification_days && $days_remaining > 0) {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <strong><?php echo esc_html__('Hosting Renewal Warning:', 'cloudtart-support'); ?></strong>
                        <?php printf(esc_html__('%s days remaining until the site hosting expires.', 'cloudtart-support'), esc_html($days_remaining)); ?>
                        <?php echo esc_html__('Please renew the hosting as soon as possible.', 'cloudtart-support'); ?>
                        <button type="button" class="button renew-hosting-btn cloudtart-renew-btn"><?php echo esc_html__('I have renewed it', 'cloudtart-support'); ?></button>
                        <a href="<?php echo esc_url($settings_url); ?>" class="cloudtart-renew-link"><?php echo esc_html__('Open expiry settings', 'cloudtart-support'); ?></a>
                    </p>
                </div>
                <?php
            } elseif ($days_remaining <= 0) {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p>
                        <strong><?php echo esc_html__('Alert:', 'cloudtart-support'); ?></strong>
                        <?php printf(esc_html__('%s days have passed since the site hosting expired!', 'cloudtart-support'), esc_html(abs($days_remaining))); ?>
                        <?php echo esc_html__('Please renew the hosting immediately.', 'cloudtart-support'); ?>
                        <button type="button" class="button renew-hosting-btn cloudtart-renew-btn"><?php echo esc_html__('I have renewed it', 'cloudtart-support'); ?></button>
                        <a href="<?php echo esc_url($settings_url); ?>" class="cloudtart-renew-link"><?php echo esc_html__('Open expiry settings', 'cloudtart-support'); ?></a>
                    </p>
                </div>
                <?php
            }
        }

        // اضافه کردن نمایش اعلان‌ها برای دامنه‌های چندگانه در دیتابیس
        global $wpdb;

        $domains = $wpdb->get_results(
            "SELECT * FROM {$this->db_table}",
            ARRAY_A
        );

        foreach ((array) $domains as $domain) {
            $domain_name = $domain['domain_name'];
            $domain_expiry = $domain['domain_expiry'];
            $domain_id = $domain['id'];

            if (!empty($domain_expiry)) {
                $expiry_date = strtotime($domain_expiry);
                $days_remaining = ceil(($expiry_date - time()) / (24 * 60 * 60));

                if ($days_remaining <= $notification_days && $days_remaining > 0) {
                    ?>
                    <div class="notice notice-warning is-dismissible">
                        <p>
                            <strong><?php echo esc_html__('Domain Renewal Warning:', 'cloudtart-support'); ?></strong>
                            <?php printf(esc_html__('%1$s days remaining until domain %2$s expires.', 'cloudtart-support'), esc_html($days_remaining), esc_html($domain_name)); ?>
                            <?php echo esc_html__('Please renew the domain as soon as possible.', 'cloudtart-support'); ?>
                            <button type="button" class="button renew-domain-btn cloudtart-renew-btn" data-domain-id="<?php echo esc_attr($domain_id); ?>"><?php echo esc_html__('I have renewed it', 'cloudtart-support'); ?></button>
                            <a href="<?php echo esc_url($settings_url); ?>" class="cloudtart-renew-link"><?php echo esc_html__('Open expiry settings', 'cloudtart-support'); ?></a>
                        </p>
                    </div>
                    <?php
                } elseif ($days_remaining <= 0) {
                    ?>
                    <div class="notice notice-error is-dismissible">
                        <p>
                            <strong><?php echo esc_html__('Alert:', 'cloudtart-support'); ?></strong>
                            <?php printf(esc_html__('%1$s days have passed since domain %2$s expired!', 'cloudtart-support'), esc_html(abs($days_remaining)), esc_html($domain_name)); ?>
                            <?php echo esc_html__('Please renew the domain immediately.', 'cloudtart-support'); ?>
                            <button type="button" class="button renew-domain-btn cloudtart-renew-btn" data-domain-id="<?php echo esc_attr($domain_id); ?>"><?php echo esc_html__('I have renewed it', 'cloudtart-support'); ?></button>
                            <a href="<?php echo esc_url($settings_url); ?>" class="cloudtart-renew-link"><?php echo esc_html__('Open expiry settings', 'cloudtart-support'); ?></a>
                        </p>
                    </div>
                    <?php
                }
            }
        }
    }
    
    /**
     * دریافت تمام دامنه‌های ثبت شده
     */
    public function get_domains() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->db_table} ORDER BY domain_expiry ASC", ARRAY_A);
    }
    
    /**
     * محاسبه تاریخ تمدید بعدی
     */
    public function calculate_next_expiry_date($current_expiry, $period, $type = 'domain') {
        if (empty($current_expiry)) {
            return '';
        }
        
        $expiry_date = strtotime($current_expiry);
        if ($expiry_date === false) {
            return '';
        }
        
        $period = max(1, (int)$period);
        
        if ($type === 'domain') {
            $expiry_date = strtotime("+{$period} years", $expiry_date);
            while ($expiry_date !== false && $expiry_date <= time()) {
                $expiry_date = strtotime("+{$period} years", $expiry_date);
            }
        } else {
            $expiry_date = strtotime("+{$period} months", $expiry_date);
            while ($expiry_date !== false && $expiry_date <= time()) {
                $expiry_date = strtotime("+{$period} months", $expiry_date);
            }
        }
        
        return $expiry_date ? date('Y-m-d', $expiry_date) : '';
    }

    /**
     * ارسال ایمیل
     */
    private function send_email($to, $subject, $message) {
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        
        // افزودن نام سایت به عنوان فرستنده
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');
        $headers[] = "From: $site_name <$admin_email>";
        
        // لاگ کردن ارسال ایمیل
        if ($this->logger) {
            $this->logger->log(sprintf(__('Email sent to %1$s with subject: %2$s', 'cloudtart-support'), $to, $subject), 'email');
        }
        
        $result = wp_mail($to, $subject, $message, $headers);
        
        if (!$result && $this->logger) {
            $this->logger->log(sprintf(__('Error sending email to %s', 'cloudtart-support'), $to), 'error');
        }
        
        return $result;
    }
    
    /**
     * دریافت تاریخ انقضای دامنه
     */
    public function get_domain_expiry_status() {
        $domain_expiry = isset($this->settings['domain_expiry']) ? $this->settings['domain_expiry'] : '';
        
        if (empty($domain_expiry)) {
            return [
                'has_expiry' => false,
                'message' => __('Expiry date is not set.', 'cloudtart-support'),
                'status' => 'info'
            ];
        }
        
        $expiry_date = strtotime($domain_expiry);
        $days_remaining = ceil(($expiry_date - time()) / (24 * 60 * 60));
        $notification_days = isset($this->settings['notification_days']) ? (int)$this->settings['notification_days'] : 10;
        
        if ($days_remaining < 0) {
            return [
                'has_expiry' => true,
                'expiry_date' => $domain_expiry,
                'days_remaining' => $days_remaining,
                'message' => sprintf(__('Expired (%s days ago)', 'cloudtart-support'), abs($days_remaining)),
                'status' => 'error'
            ];
        } elseif ($days_remaining <= $notification_days) {
            return [
                'has_expiry' => true,
                'expiry_date' => $domain_expiry,
                'days_remaining' => $days_remaining,
                'message' => sprintf(__('Near expiry (%s days remaining)', 'cloudtart-support'), $days_remaining),
                'status' => 'warning'
            ];
        } else {
            return [
                'has_expiry' => true,
                'expiry_date' => $domain_expiry,
                'days_remaining' => $days_remaining,
                'message' => sprintf(__('Valid (%s days remaining)', 'cloudtart-support'), $days_remaining),
                'status' => 'ok'
            ];
        }
    }
    
    /**
     * دریافت تاریخ انقضای هاست
     */
    public function get_hosting_expiry_status() {
        $hosting_expiry = isset($this->settings['hosting_expiry']) ? $this->settings['hosting_expiry'] : '';
        
        if (empty($hosting_expiry)) {
            return [
                'has_expiry' => false,
                'message' => __('Expiry date is not set.', 'cloudtart-support'),
                'status' => 'info'
            ];
        }
        
        $expiry_date = strtotime($hosting_expiry);
        $days_remaining = ceil(($expiry_date - time()) / (24 * 60 * 60));
        $notification_days = isset($this->settings['notification_days']) ? (int)$this->settings['notification_days'] : 10;
        
        if ($days_remaining < 0) {
            return [
                'has_expiry' => true,
                'expiry_date' => $hosting_expiry,
                'days_remaining' => $days_remaining,
                'message' => sprintf(__('Expired (%s days ago)', 'cloudtart-support'), abs($days_remaining)),
                'status' => 'error'
            ];
        } elseif ($days_remaining <= $notification_days) {
            return [
                'has_expiry' => true,
                'expiry_date' => $hosting_expiry,
                'days_remaining' => $days_remaining,
                'message' => sprintf(__('Near expiry (%s days remaining)', 'cloudtart-support'), $days_remaining),
                'status' => 'warning'
            ];
        } else {
            return [
                'has_expiry' => true,
                'expiry_date' => $hosting_expiry,
                'days_remaining' => $days_remaining,
                'message' => sprintf(__('Valid (%s days remaining)', 'cloudtart-support'), $days_remaining),
                'status' => 'ok'
            ];
        }
    }
    
    /**
     * اعتبارسنجی تنظیمات (بدون فراخوانی‌های بازگشتی)
     */
    public function sanitize_expiry_settings($settings) {
        // پر کردن کلیدهای غایب با مقادیر فعلی تا ارسال ناقص فرم تنظیمات را پاک نکند
        $settings = wp_parse_args(is_array($settings) ? $settings : [], wp_parse_args(
            is_array($this->settings) ? $this->settings : [],
            [
                'domain_expiry' => '',
                'domain_period' => '1',
                'hosting_expiry' => '',
                'hosting_period' => '1',
                'notification_emails' => '',
                'notification_days' => '10',
            ]
        ));

        // تنظیم تاریخ‌ها به فرمت استاندارد
        if (!empty($settings['domain_expiry'])) {
            $settings['domain_expiry'] = sanitize_text_field($settings['domain_expiry']);
        }
        
        if (!empty($settings['hosting_expiry'])) {
            $settings['hosting_expiry'] = sanitize_text_field($settings['hosting_expiry']);
        }
        
        // بررسی دوره‌های تمدید
        $valid_domain_periods = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
        if (!in_array($settings['domain_period'], $valid_domain_periods)) {
            $settings['domain_period'] = '1';
        }
        
        $valid_hosting_periods = ['1', '3', '6', '12', '24'];
        if (!in_array($settings['hosting_period'], $valid_hosting_periods)) {
            $settings['hosting_period'] = '1';
        }
        
        // بررسی ایمیل‌ها
        if (!empty($settings['notification_emails'])) {
            $emails = explode(',', $settings['notification_emails']);
            $valid_emails = [];
            
            foreach ($emails as $email) {
                $email = trim($email);
                if (is_email($email)) {
                    $valid_emails[] = $email;
                }
            }
            
            $settings['notification_emails'] = implode(', ', $valid_emails);
        }
        
        // بررسی روزهای اطلاع‌رسانی
        $settings['notification_days'] = absint($settings['notification_days']);
        if ($settings['notification_days'] < 1) {
            $settings['notification_days'] = '10';
        }
        
        return $settings;
    }
    
    /**
     * ذخیره تنظیمات
     */
    public function save_settings($settings) {
        // اطمینان از اینکه multiple_domains حذف شده از settings
        if (isset($settings['multiple_domains'])) {
            unset($settings['multiple_domains']);
        }
        
        $this->settings = $settings;
        update_option('cloudtart_expiry_settings', $settings);
    }
    
    /**
     * دریافت تنظیمات
     */
    public function get_settings() {
        return $this->settings;
    }
    
    /**
     * نمایش پیش‌نمایش تاریخ‌های انقضا
     */
    public function display_expiry_preview() {
        // دریافت وضعیت دامنه و هاست
        $domain_status = $this->get_domain_expiry_status();
        $hosting_status = $this->get_hosting_expiry_status();
        
        echo '<div class="expiry-preview">';
        echo '<h3>' . esc_html__('Status Preview', 'cloudtart-support') . '</h3>';
        echo '<table class="widefat">';
        
        // دامنه
        echo '<tr>';
        echo '<th>' . esc_html__('Domain Status:', 'cloudtart-support') . '</th>';
        echo '<td>';
        
        if ($domain_status['has_expiry']) {
            echo '<span class="status-' . $domain_status['status'] . '">' . $domain_status['message'] . '</span>';
        } else {
            echo '<span class="status-info">' . $domain_status['message'] . '</span>';
        }
        
        echo '</td>';
        echo '</tr>';
        
        // هاست
        echo '<tr>';
        echo '<th>' . esc_html__('Hosting Status:', 'cloudtart-support') . '</th>';
        echo '<td>';
        
        if ($hosting_status['has_expiry']) {
            echo '<span class="status-' . $hosting_status['status'] . '">' . $hosting_status['message'] . '</span>';
        } else {
            echo '<span class="status-info">' . $hosting_status['message'] . '</span>';
        }
        
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        echo '</div>';
    }
    
    /**
     * نمایش بخش مدیریت دامنه‌های چندگانه
     */
    public function display_multiple_domains() {
        $domains = $this->get_domains();
        $notification_days = isset($this->settings['notification_days']) ? (int)$this->settings['notification_days'] : 10;
        ?>
        <div class="multiple-domains-section">
            <h3><?php echo esc_html__('Manage Multiple Domains', 'cloudtart-support'); ?></h3>
            <p><?php echo esc_html__('Manage multiple domains in this section.', 'cloudtart-support'); ?></p>
            
            <div class="domain-form">
                <div class="domain-form-row">
                    <input type="text" id="domain-name" placeholder="<?php echo esc_attr__('Domain name (e.g., example.com)', 'cloudtart-support'); ?>" class="regular-text">
                    <input type="date" id="domain-expiry" placeholder="<?php echo esc_attr__('Expiry date', 'cloudtart-support'); ?>" class="regular-text">
                    <select id="renewal-period" class="regular-text" style="width: auto;">
                        <option value="1"><?php echo esc_html(sprintf(__('Renewal period: %s year', 'cloudtart-support'), 1)); ?></option>
                        <option value="2"><?php echo esc_html(sprintf(__('Renewal period: %s years', 'cloudtart-support'), 2)); ?></option>
                        <option value="3"><?php echo esc_html(sprintf(__('Renewal period: %s years', 'cloudtart-support'), 3)); ?></option>
                        <option value="5"><?php echo esc_html(sprintf(__('Renewal period: %s years', 'cloudtart-support'), 5)); ?></option>
                        <option value="10"><?php echo esc_html(sprintf(__('Renewal period: %s years', 'cloudtart-support'), 10)); ?></option>
                    </select>
                    <button type="button" id="add-domain" class="button button-secondary"><?php echo esc_html__('Add Domain', 'cloudtart-support'); ?></button>
                </div>
                <div id="domain-message"></div>
            </div>
            
            <table class="widefat" id="domains-list">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Domain Name', 'cloudtart-support'); ?></th>
                        <th><?php echo esc_html__('Expiry Date', 'cloudtart-support'); ?></th>
                        <th><?php echo esc_html__('Renewal Period', 'cloudtart-support'); ?></th>
                        <th><?php echo esc_html__('Status', 'cloudtart-support'); ?></th>
                        <th><?php echo esc_html__('Actions', 'cloudtart-support'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($domains)): ?>
                    <tr>
                        <td colspan="5"><?php echo esc_html__('No domains have been added.', 'cloudtart-support'); ?></td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($domains as $domain): 
                        $expiry_date = strtotime($domain['domain_expiry']);
                        $days_remaining = ceil(($expiry_date - time()) / (24 * 60 * 60));
                        $status_class = '';
                        $status_text = '';
                        if ($days_remaining < 0) {
                            $status_class = 'status-error';
                            $status_text = sprintf(__('Expired (%s days ago)', 'cloudtart-support'), abs($days_remaining));
                        } elseif ($days_remaining <= $notification_days) {
                            $status_class = 'status-warning';
                            $status_text = sprintf(__('Near expiry (%s days remaining)', 'cloudtart-support'), $days_remaining);
                        } else {
                            $status_class = 'status-ok';
                            $status_text = sprintf(__('Valid (%s days remaining)', 'cloudtart-support'), $days_remaining);
                        }
                        
                        // دوره تمدید
                        $renewal_period = isset($domain['renewal_period']) ? (int)$domain['renewal_period'] : 1;
                    ?>
                    <tr>
                        <td><?php echo esc_html($domain['domain_name']); ?></td>
                        <td><?php echo esc_html($domain['domain_expiry']); ?></td>
                        <td><?php printf(esc_html__('%s years', 'cloudtart-support'), esc_html($renewal_period)); ?></td>
                        <td><span class="<?php echo $status_class; ?>"><?php echo esc_html($status_text); ?></span></td>
                        <td>
                            <button type="button" class="button button-small remove-domain" data-index="<?php echo $domain['id']; ?>"><?php echo esc_html__('Remove', 'cloudtart-support'); ?></button>
                            <?php if ($days_remaining <= $notification_days || $days_remaining <= 0): ?>
                            <button type="button" class="button button-small renew-domain-btn cloudtart-renew-btn" data-domain-id="<?php echo $domain['id']; ?>"><?php echo esc_html__('I have renewed it', 'cloudtart-support'); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        jQuery(function($) {
            $('#add-domain').on('click', function() {
                var domainName = $('#domain-name').val();
                var domainExpiry = $('#domain-expiry').val();
                var renewalPeriod = $('#renewal-period').val();
                var $message = $('#domain-message');
                
                if (!domainName || !domainExpiry) {
                    $message.html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Please enter the domain name and expiry date.', 'cloudtart-support')); ?></p></div>');
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cloudtart_add_domain',
                        nonce: cloudtartSupport.nonce,
                        domain_name: domainName,
                        domain_expiry: domainExpiry,
                        renewal_period: renewalPeriod
                    },
                    beforeSend: function() {
                        $message.html('<div class="notice notice-info inline"><p><?php echo esc_js(__('Adding domain...', 'cloudtart-support')); ?></p></div>');
                    },
                    success: function(response) {
                        if (response.success) {
                            $message.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                            $('#domain-name').val('');
                            $('#domain-expiry').val('');
                            // بازنشانی صفحه برای نمایش دامنه جدید
                            location.reload();
                        } else {
                            $message.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        $message.html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Error connecting to server.', 'cloudtart-support')); ?></p></div>');
                    }
                });
            });
            
            $('.remove-domain').on('click', function() {
                var $row = $(this).closest('tr');
                var index = $(this).data('index');
                
                if (!confirm('<?php echo esc_js(__('Are you sure you want to remove this domain?', 'cloudtart-support')); ?>')) {
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cloudtart_remove_domain',
                        nonce: cloudtartSupport.nonce,
                        domain_index: index
                    },
                    beforeSend: function() {
                        $row.addClass('deleting');
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.fadeOut(function() {
                                $(this).remove();
                                // اگر آخرین دامنه بود، پیام "هیچ دامنه‌ای ثبت نشده است" را نمایش بده
                                if ($('#domains-list tbody tr').length === 0) {
                                    $('#domains-list tbody').html('<tr><td colspan="5"><?php echo esc_js(__('No domains have been added.', 'cloudtart-support')); ?></td></tr>');
                                }
                            });
                        } else {
                            $row.removeClass('deleting');
                            alert(response.data.message);
                        }
                    },
                    error: function() {
                        $row.removeClass('deleting');
                        alert('<?php echo esc_js(__('Error connecting to server.', 'cloudtart-support')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
