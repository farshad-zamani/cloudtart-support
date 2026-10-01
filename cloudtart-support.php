<?php
/**
 * Plugin Name: CloudTart Support Services Plugin
 * Plugin URI: https://www.CloudTart.com
 * Description: Support services, customizations, and updates for CloudTart web design clients
 * Version: 1.9.56
 * Author: CloudTart
 * Author URI: https://www.CloudTart.com
 * Text Domain: cloudtart-support
 * Domain Path: /languages
 * Update URI: https://cloudtart.com/update-api.php
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

// تعریف ثابت‌های پلاگین
define('CLOUDTART_SUPPORT_VERSION', '1.9.56');
define('CLOUDTART_SUPPORT_DIR', plugin_dir_path(__FILE__));
define('CLOUDTART_SUPPORT_URL', plugin_dir_url(__FILE__));
define('CLOUDTART_SUPPORT_BASENAME', plugin_basename(__FILE__));

// ارتباط با پلتفرم پشتیبانی کلادتارت در افزونه‌ی مکمل «CloudTart Support Connector»
// قرار دارد؛ این افزونه به‌تنهایی هیچ endpoint راه‌دوری ثبت نمی‌کند.
define('CLOUDTART_SUPPORT_CONNECTOR_BASENAME', 'cloudtart-support-connector/cloudtart-support-connector.php');

/**
 * آدرس سرور اعلان بروزرسانی (update-api.php) — قابل تغییر با ثابت یا فیلتر.
 */
function cloudtart_support_update_api_url() {
    $configured = defined('CLOUDTART_SUPPORT_UPDATE_API_URL') ? CLOUDTART_SUPPORT_UPDATE_API_URL : 'https://cloudtart.com/update-api.php';
    return apply_filters('cloudtart_support_update_api_url', $configured);
}

function cloudtart_support_get_storage_dir() {
    static $dirs = null;
    if ($dirs !== null) {
        return $dirs;
    }

    // wp_upload_dir(null, false): فقط مسیر لازم است؛ بررسی/ساخت پوشه‌ی ماه جاری روی هر
    // درخواست لازم نیست.
    $upload_dir = wp_upload_dir(null, false);

    $dirs = [
        'basedir' => trailingslashit($upload_dir['basedir']) . 'cloudtart-support',
        'baseurl' => trailingslashit($upload_dir['baseurl']) . 'cloudtart-support',
    ];

    return $dirs;
}

function cloudtart_support_get_log_dir() {
    $storage_dir = cloudtart_support_get_storage_dir();

    return [
        'basedir' => trailingslashit($storage_dir['basedir']) . 'logs',
        'baseurl' => trailingslashit($storage_dir['baseurl']) . 'logs',
    ];
}

function cloudtart_support_get_internal_cdn_log_file() {
    static $info = null;
    if ($info !== null) {
        return $info;
    }

    $storage_dir = cloudtart_support_get_storage_dir();
    $basedir = trailingslashit($storage_dir['basedir']) . 'internal-cdn';

    // نام غیرقابل‌حدس (برای سرورهای nginx که .htaccess را نمی‌خوانند). wp_hash پیش از
    // بارگذاری توابع pluggable وجود ندارد؛ در آن صورت نتیجه کش نمی‌شود.
    if (!function_exists('wp_hash')) {
        return ['basedir' => $basedir, 'file_path' => trailingslashit($basedir) . 'blocked-requests.log'];
    }
    $suffix = '-' . substr(wp_hash('cloudtart-support-cdn-log'), 0, 12);
    $file_path = trailingslashit($basedir) . 'blocked-requests' . $suffix . '.log';

    $old_path = trailingslashit($basedir) . 'blocked-requests.log';
    if ($suffix !== '' && is_file($old_path) && !is_file($file_path)) {
        @rename($old_path, $file_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    $info = [
        'basedir' => $basedir,
        'file_path' => $file_path,
    ];

    return $info;
}

/**
 * پوشه‌ی uploads/cloudtart-support فقط لاگ نگه می‌دارد؛ دسترسی مستقیم وب به آن بسته
 * می‌شود (Apache و LiteSpeed). فقط یک بار در هر درخواست بررسی می‌شود.
 */
function cloudtart_support_protect_storage_dir() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $storage_dir = cloudtart_support_get_storage_dir();
    $basedir = $storage_dir['basedir'];
    if (!is_dir($basedir) || file_exists($basedir . '/.htaccess')) {
        return;
    }

    @file_put_contents($basedir . '/.htaccess', "# CloudTart Support: logs are not public\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    if (!file_exists($basedir . '/index.php')) {
        @file_put_contents($basedir . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }
}

// بارگذاری کلاس‌های اصلی
require_once CLOUDTART_SUPPORT_DIR . 'includes/class-logger.php';
require_once CLOUDTART_SUPPORT_DIR . 'includes/class-monitoring.php';
require_once CLOUDTART_SUPPORT_DIR . 'includes/class-expiry-manager.php';
require_once CLOUDTART_SUPPORT_DIR . 'includes/class-updater.php';
require_once CLOUDTART_SUPPORT_DIR . 'admin/class-admin.php';
require_once CLOUDTART_SUPPORT_DIR . 'includes/class-cloudtart-support.php';
require_once CLOUDTART_SUPPORT_DIR . 'includes/class-internal-cdn.php';

// ثبت هوک برای فعال‌سازی پلاگین و ساخت جدول دیتابیس دامنه‌ها
register_activation_hook(__FILE__, ['CloudTart_Support_Expiry_Manager', 'activate']);

// بارگذاری پلاگین
function cloudtart_support_init() {
    return CloudTart_Support::get_instance();
}

/**
 * دسترسی به نمونه‌ی اصلی پلاگین برای افزونه‌های مکمل (مانند کانکتور).
 */
function cloudtart_support() {
    return cloudtart_support_init();
}

/**
 * آیا افزونه‌ی کانکتور (ارتباط با پلتفرم پشتیبانی) فعال است؟
 */
function cloudtart_support_connector_active() {
    return (bool) apply_filters('cloudtart_support_connector_active', false);
}

function cloudtart_support_load_textdomain() {
    load_plugin_textdomain('cloudtart-support', false, dirname(CLOUDTART_SUPPORT_BASENAME) . '/languages');
}

function cloudtart_support_bootstrap() {
    global $cloudtart_support;
    $cloudtart_support = cloudtart_support_init();
}

// اینترنت داخلی / CDN داخلی همین حالا (هنگام بارگذاری افزونه) راه‌اندازی می‌شود تا
// درخواست‌هایی که افزونه‌های دیگر در plugins_loaded می‌فرستند هم مدیریت شوند.
CloudTart_Internal_CDN::boot();

// راه‌اندازی آپدیت‌کننده — فقط جایی که وردپرس آپدیت‌ها را بررسی یا نصب می‌کند
// (پیشخوان، کرون، WP-CLI)؛ بازدیدهای عادی سایت هزینه‌ای برای آن نمی‌پردازند.
function cloudtart_support_updater_init() {
    if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
        new CloudTart_Support_Updater();
    }
}

// اضافه کردن بازه زمانی 5 دقیقه‌ای برای کرون
function cloudtart_support_add_cron_intervals($schedules) {
    $schedules['five_minutes'] = [
        'interval' => 300, // 5 دقیقه
        'display' => did_action('init') ? __('Every 5 minutes', 'cloudtart-support') : 'Every 5 minutes'
    ];
    return $schedules;
}
add_filter('cron_schedules', 'cloudtart_support_add_cron_intervals');

// اجرای پلاگین
add_action('init', 'cloudtart_support_load_textdomain', 0);
add_action('init', 'cloudtart_support_bootstrap', 1);
add_action('init', 'cloudtart_support_updater_init');

// استایل جداول سورت‌پذیر به assets/css/admin.css منتقل شده است تا فقط در صفحه
// تنظیمات پلاگین بارگذاری شود و روی سایر صفحات پیشخوان اثر نگذارد.
