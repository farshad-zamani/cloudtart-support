<?php
/**
 * کلاس مانیتورینگ سایت
 */

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

class CloudTart_Support_Monitoring {
    private $logger;
    private $settings;

    /** هندلر خطایی که پیش از ما ثبت شده بود (Query Monitor و ...) — خطاها به آن هم می‌رسند. */
    private $previous_error_handler = null;

    /** خطاهایی که در همین درخواست ثبت شده‌اند (هر خطا یک بار). */
    private $seen_errors = [];

    /** سقف ثبت خطا در هر درخواست؛ حلقه‌ای که هزاران هشدار تولید کند سایت را کند نمی‌کند. */
    const MAX_ERRORS_PER_REQUEST = 20;

    /** یک خطای تکراری حداکثر هر یک ساعت یک بار در لاگ نوشته/ایمیل/گزارش می‌شود. */
    const ERROR_THROTTLE_TTL = HOUR_IN_SECONDS;

    const THROTTLE_OPTION = 'cloudtart_error_throttle';

    /**
     * فقط این سطوح ثبت می‌شوند. Notice و Deprecated (که در PHP 8.x از افزونه‌ها و
     * قالب‌های دیگر به‌وفور تولید می‌شوند) نادیده گرفته می‌شوند تا هزینه‌ای نداشته باشند.
     */
    const LOGGED_LEVELS = E_WARNING | E_USER_WARNING | E_USER_ERROR | E_RECOVERABLE_ERROR | E_CORE_WARNING | E_COMPILE_WARNING;

    /**
     * سازنده کلاس
     */
    public function __construct($logger) {
        $this->logger = $logger;
        $this->settings = get_option('cloudtart_monitoring_settings', [
            'status_monitoring' => '1',
            'error_monitoring' => '1',
            'notification_email' => '',
            'check_interval' => 'hourly'
        ]);

        // راه‌اندازی هوک‌ها
        $this->setup_hooks();
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function setup_hooks() {
        // اضافه کردن هوک برای بررسی وضعیت سایت
        add_action('cloudtart_check_site_status', [$this, 'check_site_status']);

        // اضافه کردن هندلر خطا برای ثبت خطاهای PHP در زمان واقعی
        if (isset($this->settings['error_monitoring']) && $this->settings['error_monitoring'] === '1') {
            $this->previous_error_handler = set_error_handler([$this, 'handle_php_error']);
        }
    }

    /**
     * نام خوانای سطح خطا. E_STRICT (2048) از PHP 8.4 منسوخ است و در PHP 8 هرگز
     * تولید نمی‌شود؛ مقدار عددی آن استفاده شده تا هشدار Deprecated ایجاد نشود.
     */
    private function get_error_type_name($type) {
        $names = [
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            2048 => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];

        return isset($names[$type]) ? $names[$type] : 'UNKNOWN_ERROR';
    }

    /**
     * ثبت و گزارش خطاهای PHP در زمان واقعی
     *
     * این هندلر روی همه‌ی درخواست‌های سایت اجرا می‌شود، پس مسیر عادی آن باید تقریباً
     * هزینه‌ای نداشته باشد: سطوح کم‌اهمیت فوراً رد می‌شوند و هر خطا در هر درخواست یک
     * بار و در کل حداکثر ساعتی یک بار ثبت می‌شود.
     */
    public function handle_php_error($errno, $errstr, $errfile = '', $errline = 0) {
        if (($errno & self::LOGGED_LEVELS) && (error_reporting() & $errno)) {
            $this->record_php_error((int) $errno, (string) $errstr, (string) $errfile, (int) $errline);
        }

        if ($this->previous_error_handler) {
            return call_user_func($this->previous_error_handler, $errno, $errstr, $errfile, $errline);
        }

        // false: هندلر استاندارد PHP هم خطا را طبق تنظیمات سرور ثبت کند
        return false;
    }

    private function record_php_error($errno, $errstr, $errfile, $errline) {
        $signature = md5($errno . '|' . $errfile . '|' . $errline . '|' . $errstr);
        if (isset($this->seen_errors[$signature]) || count($this->seen_errors) >= self::MAX_ERRORS_PER_REQUEST) {
            return;
        }
        $this->seen_errors[$signature] = true;

        if (!$this->throttle_allows('e:' . $signature, self::ERROR_THROTTLE_TTL)) {
            return;
        }

        $error_type = $this->get_error_type_name($errno);
        $this->logger->log(sprintf(__('[%1$s] %2$s in %3$s on line %4$s', 'cloudtart-support'), $error_type, $errstr, $errfile, $errline), 'error');

        // فقط خطاهای جدی ایمیل می‌شوند (خطاهای مرگبار در catch_fatal_error بررسی می‌شوند)
        if (!in_array($errno, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            return;
        }

        $this->logger->log_error("[$error_type] $errstr in $errfile on line $errline");

        $site_url = home_url();
        $subject = sprintf(__('PHP error on site %s', 'cloudtart-support'), wp_parse_url($site_url, PHP_URL_HOST));
        $message = __('A PHP error occurred on your site:', 'cloudtart-support') . "\n\n";
        $message .= sprintf(__('Site: %1$s (%2$s)', 'cloudtart-support'), get_bloginfo('name'), $site_url) . "\n";
        $message .= sprintf(__('Error type: %s', 'cloudtart-support'), $error_type) . "\n";
        $message .= sprintf(__('Error message: %s', 'cloudtart-support'), $errstr) . "\n";
        $message .= sprintf(__('File: %s', 'cloudtart-support'), $errfile) . "\n";
        $message .= sprintf(__('Line: %s', 'cloudtart-support'), $errline) . "\n\n";
        $message .= sprintf(__('Occurrence time: %s', 'cloudtart-support'), date('Y-m-d H:i:s')) . "\n";
        $message .= sprintf(__('Page URL: %s', 'cloudtart-support'), (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : __('Unknown', 'cloudtart-support'))) . "\n";
        $message .= __('This message was sent by CloudTart Monitoring Service.', 'cloudtart-support');

        foreach ($this->notification_emails() as $email) {
            $this->send_email($email, $subject, $message);
        }
    }

    /**
     * آیا این رویداد (با کلید مشخص) در بازه‌ی ttl قبلاً ثبت نشده است؟
     *
     * وضعیت در یک option غیر autoload نگه داشته می‌شود: فقط وقتی خطایی رخ داده خوانده
     * می‌شود و فقط وقتی رویداد واقعاً ثبت می‌شود نوشته می‌شود.
     */
    private function throttle_allows($key, $ttl) {
        if (!function_exists('get_option') || !did_action('plugins_loaded')) {
            return true;
        }

        $now = time();
        $state = get_option(self::THROTTLE_OPTION, []);
        $state = is_array($state) ? $state : [];

        if (isset($state[$key]) && ($now - (int) $state[$key]) < $ttl) {
            return false;
        }

        $state[$key] = $now;
        if (count($state) > 200) {
            asort($state);
            $state = array_slice($state, -150, null, true);
        }
        update_option(self::THROTTLE_OPTION, $state, false);

        return true;
    }

    private function notification_emails() {
        $notification_email = !empty($this->settings['notification_email']) ? $this->settings['notification_email'] : get_option('admin_email');
        $emails = array_filter(array_map('trim', explode(',', (string) $notification_email)));

        // تا 5 ایمیل را پشتیبانی می‌کنیم
        return array_slice($emails, 0, 5);
    }

    /**
     * بررسی وضعیت سایت و ارسال اطلاع‌رسانی در صورت وجود مشکل
     */
    public function check_site_status() {
        // بررسی فعال بودن مانیتورینگ
        if (!isset($this->settings['status_monitoring']) || $this->settings['status_monitoring'] !== '1') {
            return;
        }
        
        $site_url = home_url();
        $this->logger->log(sprintf(__('Checking site status: %s', 'cloudtart-support'), $site_url), 'monitoring');
        
        $response = wp_remote_get($site_url, [
            'timeout' => 20,
            'sslverify' => false,
            'headers' => [
                'User-Agent' => 'CloudTart Monitoring Service'
            ]
        ]);
        
        $monitoring_log = [
            'time' => time(),
            'type' => 'site_status',
            'status' => 'success',
            'message' => __('Site status is normal.', 'cloudtart-support')
        ];
        $status_code = 0;
        $response_time = 0;
        
        // بررسی وجود خطا در درخواست
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log(sprintf(__('Error accessing site: %s', 'cloudtart-support'), $error_message), 'error');
            
            // یک مشکل ماندگار (مثلاً مسدود بودن درخواست loopback روی هاست) هر ۶ ساعت یک بار
            // ایمیل می‌شود، نه هر ساعت.
            $notification_emails = $this->throttle_allows('s:connect', 6 * HOUR_IN_SECONDS) ? $this->notification_emails() : [];
            
            $subject = sprintf(__('Alert: site access error on %s', 'cloudtart-support'), wp_parse_url($site_url, PHP_URL_HOST));
            $message = __('Your site is experiencing an issue:', 'cloudtart-support') . "\n\n";
            $message .= sprintf(__('Site URL: %s', 'cloudtart-support'), $site_url) . "\n";
            $message .= __('Error type: Connection error', 'cloudtart-support') . "\n";
            $message .= sprintf(__('Error message: %s', 'cloudtart-support'), $error_message) . "\n";
            $message .= sprintf(__('Detected at: %s', 'cloudtart-support'), date('Y-m-d H:i:s')) . "\n\n";
            $message .= __('This message was sent by CloudTart Monitoring Service.', 'cloudtart-support');
            
            foreach ($notification_emails as $email) {
                $this->send_email($email, $subject, $message);
            }
            
            $monitoring_log['status'] = 'error';
            $monitoring_log['message'] = sprintf(__('Error accessing site: %s', 'cloudtart-support'), $error_message);
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            
            // اطمینان از وجود کد وضعیت
            if (empty($status_code)) {
                $status_code = 0;
            }
            
            // بررسی کدهای وضعیت HTTP
            if ($status_code >= 400) {
                $this->logger->log(sprintf(__('HTTP error while accessing site. Status code: %s', 'cloudtart-support'), $status_code), 'error');
                
                // ارسال ایمیل اطلاع‌رسانی برای کدهای خطای 403 و 503
                if ($status_code == 403 || $status_code == 503) {
                    $notification_emails = $this->throttle_allows('s:http' . (int) $status_code, 6 * HOUR_IN_SECONDS) ? $this->notification_emails() : [];
                    
                    $subject = sprintf(__('Alert: HTTP error code %1$s on %2$s', 'cloudtart-support'), $status_code, wp_parse_url($site_url, PHP_URL_HOST));
                    $message = __('Your site returned an HTTP error code:', 'cloudtart-support') . "\n\n";
                    $message .= sprintf(__('Site URL: %s', 'cloudtart-support'), $site_url) . "\n";
                    $message .= sprintf(__('Status code: %s', 'cloudtart-support'), $status_code) . "\n";
                    $message .= sprintf(__('Detected at: %s', 'cloudtart-support'), date('Y-m-d H:i:s')) . "\n\n";
                    $message .= __('This message was sent by CloudTart Monitoring Service.', 'cloudtart-support');
                    
                    foreach ($notification_emails as $email) {
                        $this->send_email($email, $subject, $message);
                    }
                    
                    $monitoring_log['status'] = 'error';
                    $monitoring_log['message'] = sprintf(__('HTTP error: status code %s', 'cloudtart-support'), $status_code);
                }
            } else {
                $this->logger->log(sprintf(__('Site status is normal. Status code: %s', 'cloudtart-support'), $status_code), 'monitoring');
                
                // بررسی زمان پاسخگویی
                $info = $response['http_response']->get_response_object();
                $response_time = isset($info->total_time) ? $info->total_time : 0;
                
                if ($response_time > 5) {
                    $this->logger->log(sprintf(__('Warning: site response time is high: %s seconds', 'cloudtart-support'), $response_time), 'warning');
                    
                    $monitoring_log['status'] = 'warning';
                    $monitoring_log['message'] = sprintf(__('Slow response time: %s seconds', 'cloudtart-support'), $response_time);
                }
            }
        }
        
        // ذخیره لاگ مانیتورینگ
        $this->save_monitoring_log($monitoring_log);

        /**
         * افزونه‌های مکمل (مانند کانکتور پلتفرم پشتیبانی) می‌توانند نتیجه‌ی بررسی
         * را به سرور خود گزارش کنند.
         */
        do_action('cloudtart_support_site_checked', $status_code, $response_time, $monitoring_log);
    }

    /**
     * ثبت و گزارش خطاهای فاتال PHP
     */
    public function catch_fatal_error() {
        static $handled = false;

        // shutdown ممکن است از چند مسیر فراخوانی شود؛ هر خطا فقط یک بار گزارش می‌شود
        if ($handled) {
            return;
        }
        $handled = true;

        // بررسی فعال بودن مانیتورینگ خطا
        if (!isset($this->settings['error_monitoring']) || $this->settings['error_monitoring'] !== '1') {
            return;
        }
        
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            // خطایی که روی هر بازدید تکرار شود، هر ۱۵ دقیقه یک بار (نه به ازای هر بازدید)
            // لاگ، ایمیل و به پلتفرم گزارش می‌شود.
            $signature = md5($error['type'] . '|' . $error['file'] . '|' . $error['line'] . '|' . $error['message']);
            if (!$this->throttle_allows('f:' . $signature, 15 * MINUTE_IN_SECONDS)) {
                return;
            }

            // پاسخ همین حالا به بازدیدکننده تحویل داده شود؛ ارسال ایمیل و گزارش نباید
            // صفحه‌ی خطا را برای او چند ثانیه دیرتر کند.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }

            // ایجاد پیام خطا
            $message = sprintf(__('Error type: %s', 'cloudtart-support'), $this->get_error_type_name($error['type'])) . "\n";
            $message .= sprintf(__('Error message: %s', 'cloudtart-support'), $error['message']) . "\n";
            $message .= sprintf(__('File: %1$s (line %2$s)', 'cloudtart-support'), $error['file'], $error['line']);
            
            // ثبت خطا در لاگ
            $this->logger->log_error($message);
            
            $notification_emails = $this->notification_emails();
            
            $subject = sprintf(__('Critical PHP error on site %s', 'cloudtart-support'), wp_parse_url(home_url(), PHP_URL_HOST));
            
            $email_message = __('A critical PHP error occurred on your site:', 'cloudtart-support') . "\n\n";
            $email_message .= $message . "\n\n";
            $email_message .= sprintf(__('Occurrence time: %s', 'cloudtart-support'), date('Y-m-d H:i:s')) . "\n";
            $email_message .= sprintf(__('Page URL: %s', 'cloudtart-support'), (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : __('Unknown', 'cloudtart-support'))) . "\n";
            $email_message .= sprintf(__('User agent: %s', 'cloudtart-support'), (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : __('Unknown', 'cloudtart-support'))) . "\n\n";
            $email_message .= __('This message was sent by CloudTart Monitoring Service.', 'cloudtart-support');
            
            foreach ($notification_emails as $email) {
                $this->send_email($email, $subject, $email_message);
            }
            
            /**
             * ارسال به پلتفرم پشتیبانی بر عهده‌ی افزونه‌ی کانکتور است (اگر نصب باشد).
             */
            $error['type_name'] = $this->get_error_type_name($error['type']);
            do_action('cloudtart_support_fatal_error', $message, $error);
        }
    }

    /**
     * ذخیره رکورد لاگ مانیتورینگ
     */
    private function save_monitoring_log($log) {
        $logs = get_option('cloudtart_monitoring_logs', []);
        $logs[] = $log;
        
        // محدود کردن تعداد لاگ‌ها به 50 مورد آخر
        if (count($logs) > 50) {
            $logs = array_slice($logs, -50);
        }
        
        update_option('cloudtart_monitoring_logs', $logs, false);
    }
    
    /**
     * دریافت تگ‌های مانیتورینگ
     */
    public function get_monitoring_logs() {
        return get_option('cloudtart_monitoring_logs', []);
    }

    /**
     * ارسال ایمیل
     */
    private function send_email($to, $subject, $message) {
        // بررسی وجود تابع wp_mail (در برخی محیط‌ها ممکن است هنوز لود نشده باشد)
        if (!function_exists('wp_mail')) {
            // تلاش برای بارگذاری pluggable.php اگر موجود نباشد
            if (file_exists(ABSPATH . 'wp-includes/pluggable.php')) {
                require_once ABSPATH . 'wp-includes/pluggable.php';
            }
        }

        if (!function_exists('wp_mail')) {
            $this->logger->log(__('wp_mail function not found. Unable to send email.', 'cloudtart-support'), 'error');
            return false;
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        
        // افزودن نام سایت به عنوان فرستنده
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');
        $headers[] = "From: $site_name <$admin_email>";
        
        // لاگ کردن ارسال ایمیل
        $this->logger->log(sprintf(__('Email sent to %1$s with subject: %2$s', 'cloudtart-support'), $to, $subject), 'email');
        
        $result = wp_mail($to, $subject, $message, $headers);
        
        if (!$result) {
            $this->logger->log(sprintf(__('Error sending email to %s', 'cloudtart-support'), $to), 'error');
        }
        
        return $result;
    }
    
    /**
     * ذخیره تنظیمات مانیتورینگ
     */
    public function save_settings($settings) {
        $this->settings = $settings;
        update_option('cloudtart_monitoring_settings', $settings);
    }
    
    /**
     * دریافت تنظیمات مانیتورینگ
     */
    public function get_settings() {
        return $this->settings;
    }
}
