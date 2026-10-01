<?php
/**
 * کلاس مدیریت لاگ‌ها
 */

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

class CloudTart_Support_Logger {
    private $debug;
    private $log_dir;
    private $cache_duration = 300; // 5 دقیقه
    private $cache_prefix = 'cloudtart_logs_';
    private $retention_weeks = 2;
    private $cleanup_done = false;
    private $week_key = null;

    /**
     * سازنده کلاس
     */
    public function __construct($debug = false) {
        $this->debug = $debug;
        $log_dir_info = function_exists('cloudtart_support_get_log_dir')
            ? cloudtart_support_get_log_dir()
            : ['basedir' => CLOUDTART_SUPPORT_DIR . 'logs'];

        $this->log_dir = $log_dir_info['basedir'];

        $this->ensure_log_dir();
        $this->migrate_legacy_logs();
    }

    /**
     * ثبت لاگ
     */
    public function log($message, $type = 'info') {
        if (!$this->debug && $type == 'debug') {
            return;
        }

        $this->write('debug', '[' . date('Y-m-d H:i:s') . "] [$type] $message" . PHP_EOL);
    }

    /**
     * ثبت خطا
     */
    public function log_error($message, $type = 'fatal') {
        $this->write('errors', '[' . date('Y-m-d H:i:s') . "] [$type] $message" . PHP_EOL);
    }

    /**
     * نوشتن یک خط در لاگ هفته‌ی جاری.
     *
     * قبلاً پس از هر خط یک کوئری DELETE ... LIKE روی جدول wp_options اجرا می‌شد (پاک‌کردن
     * کش نمایش لاگ) و همراه با ثبت خطاهای PHP، روی هر بازدید ده‌ها کوئری سنگین می‌ساخت.
     * کش نمایش لاگ خودش با زمان تغییر فایل اعتبارسنجی می‌شود، پس پاک‌کردن لازم نیست.
     * پاک‌سازی فایل‌های قدیمی هم فقط هنگام ساخت فایل هفته‌ی جدید انجام می‌شود.
     */
    private function write($type, $line) {
        $log_file = $this->get_weekly_log_file($type);
        if (!file_exists($log_file)) {
            $this->cleanup_old_weekly_logs();
        }

        @error_log($line, 3, $log_file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    /**
     * دریافت لاگ‌های عادی با کش
     */
    public function get_logs($lines = 50) {
        return $this->read_latest('debug', $lines, __('No logs found.', 'cloudtart-support'), __('Error reading log file.', 'cloudtart-support'));
    }

    /**
     * دریافت لاگ‌های خطا با کش
     */
    public function get_error_logs($lines = 50) {
        return $this->read_latest('errors', $lines, __('No error logs found.', 'cloudtart-support'), __('Error reading error log file.', 'cloudtart-support'));
    }

    private function read_latest($type, $lines, $empty_message, $error_message) {
        $cache_key = $this->cache_prefix . $type . '_' . (int) $lines;
        $log_file = $this->get_latest_weekly_log_file($type);

        $cached_logs = $this->get_cached_logs($cache_key, $log_file);
        if ($cached_logs !== false) {
            return $cached_logs;
        }

        if (!$log_file || !file_exists($log_file)) {
            return $empty_message;
        }

        $result = $this->tail($log_file, (int) $lines);
        if ($result === false) {
            return $error_message;
        }

        $this->set_cached_logs($cache_key, $result);

        return $result;
    }

    /**
     * چند خط آخر فایل، بدون خواندن کل فایل در حافظه (لاگ ممکن است چند ده مگابایت باشد).
     *
     * @return string|false
     */
    private function tail($file, $lines) {
        $handle = @fopen($file, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if (!$handle) {
            return false;
        }

        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);
        $buffer = '';
        $block = 8192;

        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = (int) min($block, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = fread($handle, $read) . $buffer;
        }
        fclose($handle);

        $rows = array_filter(explode("\n", str_replace("\r\n", "\n", $buffer)), 'strlen');

        return implode("\n", array_slice($rows, -$lines));
    }

    /**
     * فعال/غیرفعال کردن حالت دیباگ
     */
    public function set_debug_mode($debug) {
        $this->debug = (bool)$debug;
    }

    /**
     * دریافت لاگ‌های کش شده
     */
    private function get_cached_logs($cache_key, $log_file) {
        if (empty($log_file) || !file_exists($log_file)) {
            return false;
        }

        $transient = get_transient($cache_key);

        if ($transient !== false) {
            // بررسی تاریخ تغییر فایل
            $file_modified = filemtime($log_file);
            $cache_time = get_transient($cache_key . '_time');

            // اگر فایل بعد از کش تغییر کرده، کش را نادیده بگیر
            if ($cache_time && $file_modified < $cache_time) {
                return $transient;
            }
        }

        return false;
    }

    /**
     * ذخیره لاگ‌ها در کش
     */
    private function set_cached_logs($cache_key, $data) {
        set_transient($cache_key, $data, $this->cache_duration);
        set_transient($cache_key . '_time', time(), $this->cache_duration);
    }

    /**
     * پاک کردن کش لاگ‌ها
     */
    public function clear_logs_cache() {
        global $wpdb;

        if (!is_object($wpdb) || !isset($wpdb->options)) {
            return;
        }

        // پاک کردن تمام transient های مربوط به لاگ‌ها
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . $this->cache_prefix) . '%',
                $wpdb->esc_like('_transient_timeout_' . $this->cache_prefix) . '%'
            )
        );
    }

    private function ensure_log_dir() {
        if (!is_dir($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }

        $index_file = $this->log_dir . '/index.php';
        if (!file_exists($index_file)) {
            @file_put_contents($index_file, "<?php\n// Silence is golden."); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        // لاگ‌ها داخل uploads هستند؛ روی Apache/LiteSpeed دسترسی مستقیم بسته می‌شود و
        // برای nginx نام فایل‌ها قابل حدس نیست (file_suffix).
        if (function_exists('cloudtart_support_protect_storage_dir')) {
            cloudtart_support_protect_storage_dir();
        }
    }

    private function migrate_legacy_logs() {
        $legacy_log_dir = CLOUDTART_SUPPORT_DIR . 'logs';

        if ($legacy_log_dir === $this->log_dir || !is_dir($legacy_log_dir)) {
            return;
        }

        foreach ((array) glob($legacy_log_dir . '/*') as $legacy_file) {
            if (!is_file($legacy_file)) {
                continue;
            }

            $target_file = $this->log_dir . '/' . basename($legacy_file);

            if (file_exists($target_file)) {
                continue;
            }

            if (!@rename($legacy_file, $target_file)) {
                $contents = @file_get_contents($legacy_file);
                if ($contents === false) {
                    continue;
                }

                if (@file_put_contents($target_file, $contents) === false) {
                    continue;
                }

                @unlink($legacy_file);
            }
        }

        $remaining_files = glob($legacy_log_dir . '/*');
        if (empty($remaining_files)) {
            @rmdir($legacy_log_dir);
        }
    }

    private function get_weekly_log_file($type) {
        // Uses current_datetime()->format() rather than wp_date() so that a
        // site-wide Jalali/Persian calendar filter on the 'wp_date' hook
        // can't mangle this internal rotation key (it produced strings like
        // "1405-\1717", which contain a backslash that broke the file path).
        if ($this->week_key === null) {
            $this->week_key = current_datetime()->format('o-\WW');
        }

        return $this->log_dir . '/' . $type . '-' . $this->week_key . $this->file_suffix() . '.log';
    }

    /**
     * پسوند ثابت ولی غیرقابل‌حدس برای هر سایت (بر پایه‌ی کلیدهای wp-config)، تا روی
     * سرورهایی که .htaccess را نمی‌خوانند (nginx) هم آدرس لاگ قابل حدس نباشد.
     */
    private function file_suffix() {
        static $suffix = null;
        if ($suffix === null) {
            $suffix = function_exists('wp_hash') ? '-' . substr(wp_hash('cloudtart-support-logs'), 0, 12) : '';
        }
        return $suffix;
    }

    private function get_latest_weekly_log_file($type) {
        $current_file = $this->get_weekly_log_file($type);
        if (file_exists($current_file)) {
            return $current_file;
        }

        $files = glob($this->log_dir . '/' . $type . '-*.log');
        if (empty($files)) {
            return false;
        }

        usort($files, function ($a, $b) {
            return (int) @filemtime($b) - (int) @filemtime($a);
        });

        return $files[0];
    }

    private function cleanup_old_weekly_logs() {
        if ($this->cleanup_done) {
            return;
        }

        $this->cleanup_done = true;

        foreach (['debug', 'errors'] as $type) {
            $files = glob($this->log_dir . '/' . $type . '-*.log');
            if (empty($files)) {
                continue;
            }

            // مرتب‌سازی بر اساس زمان تغییر (نام فایل‌های قدیمی پسوند ندارد و ترتیب الفبایی
            // دیگر با ترتیب زمانی یکی نیست).
            usort($files, function ($a, $b) {
                return (int) @filemtime($b) - (int) @filemtime($a);
            });
            $files_to_delete = array_slice($files, $this->retention_weeks);

            foreach ($files_to_delete as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
