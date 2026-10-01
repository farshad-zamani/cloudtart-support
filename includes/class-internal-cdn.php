<?php
/**
 * اینترنت داخلی (اینترانت) و CDN داخلی
 *
 * وقتی اینترنت بین‌الملل قطع است، هر درخواستی به سرورهای خارجی تا پایان timeout
 * (۵ تا ۳۰ ثانیه) معطل می‌ماند؛ هم در سرور (بررسی آپدیت وردپرس و افزونه‌ها، لایسنس،
 * API ها) و هم در مرورگر بازدیدکننده (اسکریپت، استایل و فونت‌های CDN که بارگذاری
 * صفحه را متوقف می‌کنند). این کلاس سه کار انجام می‌دهد:
 *
 *   1) درخواست‌های سمت سرور (WP HTTP API) به میزبان‌های مسدود — یا در «حالت اینترانت»
 *      به هر میزبان خارجیِ مجازنشده — را بلافاصله با پاسخ خالی برمی‌گرداند.
 *   2) فایل‌های CDN صفحه (اسکریپت‌ها و استایل‌های صف‌شده و تگ‌های داخل HTML) را به
 *      نسخه‌ی محلی هدایت می‌کند و در صورت نیاز باقی فایل‌های خارجی را حذف می‌کند.
 *   3) منابع جانبی وردپرس (ایموجی s.w.org، گراواتار، preconnect/dns-prefetch) را که
 *      به سرور خارجی وصل می‌شوند غیرفعال یا محلی می‌کند.
 *
 * همه‌ی قابلیت‌ها به‌صورت پیش‌فرض خاموش‌اند.
 */

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

class CloudTart_Internal_CDN {

    const OPTION = 'cloudtart_internal_cdn_settings';

    /** بافر خروجی در تکه‌های ۴ مگابایتی تخلیه می‌شود تا دانلودهای حجیم در حافظه جمع نشوند. */
    const BUFFER_CHUNK = 4194304;

    private static $instance = null;

    private $settings = [];
    private $site_hosts = null;
    private $replacement_map = null;
    private $local_bases = null;
    private $logged = [];

    /**
     * بارگذاری هنگام لود فایل افزونه (نه در init) تا درخواست‌هایی که افزونه‌های دیگر
     * در plugins_loaded می‌فرستند هم مدیریت شوند.
     */
    public static function boot() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->settings = self::get_settings();

        if (is_admin()) {
            add_action('admin_init', [$this, 'migrate_legacy_log_file']);
        }

        if ($this->is_any_feature_enabled()) {
            $this->init();
        }
    }

    /* ------------------------------------------------------------------
     * تنظیمات
     * ---------------------------------------------------------------- */

    public static function default_settings() {
        return [
            'enabled' => '0',
            'block_external_requests' => '0',
            'block_domains' => '0',
            'block_external_http' => '0',
            'allow_ir_domains' => '1',
            'log_blocked_requests' => '0',
            'replacements' => self::default_replacements(),
            'blocked_domains' => [
                'wordpress.org',
                'wordpress.com',
                'fa.wordpress.org',
                'fa.wp.org',
                'wp.org',
                'api.wordpress.org',
                'downloads.wordpress.org',
                'plugins.svn.wordpress.org',
                'themes.svn.wordpress.org',
                's.w.org',
                'secure.gravatar.com',
                'gravatar.com',
                'akismet.com',
            ],
            'allowed_domains' => [
                'aparat.com',
                'cloudtart.com',
            ],
            'allowed_keywords' => [
                'zarinpal',
                'yektanet',
                'shaparak',
                'goftino',
                'zhaket',
                'sep.',
                'bep.',
                'bpi.',
                'bmi.',
                'zhkt',
                'asan',
                'ippanel',
                'farazsms',
                'sms',
                'iranpayamak',
                'payamak',
                'payment',
                'pay',
                'aparat',
            ],
        ];
    }

    public static function default_replacements() {
        $map = [
            'https://cdn.datatables.net/2.3.2/js/dataTables.bootstrap5.min.js' => 'dataTables.bootstrap5.min.js',
            'https://cdn.datatables.net/2.3.2/js/dataTables.min.js' => 'dataTables.min.js',
            'https://cdn.datatables.net/2.3.2/css/dataTables.bootstrap5.min.css' => 'dataTables.bootstrap5.min.css',
            'https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/js/tabler.min.js' => 'tabler.min.js',
            'https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler.min.css' => 'tabler.min.css',
            'https://afarkas.github.io/lazysizes/lazysizes.min.js' => 'lazysizes.min.js',
            'https://fonts.googleapis.com/css2?family=Roboto%3Awght%40400%3B500%3B700&display=swap' => 'roboto-custom.css',
            'https://cdnjs.cloudflare.com/ajax/libs/prism/1.23.0/plugins/autoloader/prism-autoloader.min.js' => 'prism-autoloader.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/prism/1.23.0/plugins/toolbar/prism-toolbar.min.js' => 'prism-toolbar.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/prism/1.23.0/plugins/line-highlight/prism-line-highlight.min.js' => 'prism-line-highlight.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/prism/1.23.0/plugins/line-numbers/prism-line-numbers.min.js' => 'prism-line-numbers.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/prism/1.23.0/plugins/normalize-whitespace/prism-normalize-whitespace.min.js' => 'prism-normalize-whitespace.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/prism/1.23.0/components/prism-core.min.js' => 'prism-core.min.js',
            'https://fonts.googleapis.com/css?family=Roboto%3A300%2C400%2C500%2C700' => 'roboto-custom.css',
            'https://fonts.googleapis.com/css?family=Great+Vibes:100,100italic,200,200italic,300,300italic,400,400italic,500,500italic,600,600italic,700,700italic,800,800italic,900,900italic&display=swap' => 'GreatVibesCustom.css',
            'https://cdn.jsdelivr.net/npm/ace-builds@1.43.2/src-min-noconflict/ext-language_tools.js' => 'ext-language_tools.js',
            'https://cdn.jsdelivr.net/npm/ace-builds@1.43.2/src-min-noconflict/ace.min.js' => 'ace.min.js',
            'https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap' => 'roboto-custom.css',
            'https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap' => 'DMSans-custom.css',
            'https://fonts.googleapis.com/css2?family=Source%20Serif%20Pro:wght@700&display=swap' => 'SourceSerif-Pro-custom.css',
            'https://cdnjs.cloudflare.com/ajax/libs/prism/1.23.0/plugins/copy-to-clipboard/prism-copy-to-clipboard.min.js' => 'prism-copy-to-clipboard.min.js',
            'https://fonts.googleapis.com/css?family=Roboto:100,100italic,200,200italic,300,300italic,400,400italic,500,500italic,600,600italic,700,700italic,800,800italic,900,900italic' => 'roboto-custom.css',
            'https://fonts.googleapis.com/css?family=Roboto%20Slab:100,100italic,200,200italic,300,300italic,400,400italic,500,500italic,600,600italic,700,700italic,800,800italic,900,900italic' => 'roboto-custom.css',
            'https://fonts.googleapis.com/css?family=Great%20Vibes:100,100italic,200,200italic,300,300italic,400,400italic,500,500italic,600,600italic,700,700italic,800,800italic,900,900italic' => 'GreatVibesCustom.css',
            'https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.0/clipboard.min.js' => 'clipboard.min.js',
            'https://fonts.googleapis.com/icon?family=Material+Icons' => 'Material-Icons.css',
            'https://ams.wpml.org/mini_app/dashboard.js' => 'dashboard.js',
            'https://unpkg.com/libphonenumber-js@latest/bundle/libphonenumber-max.js' => 'libphonenumber-max.js',
            'https://use.fontawesome.com/releases/v5.8.1/css/all.css' => 'all.css',
            'https://maps.googleapis.com/maps/api/js?callback=__gmap3' => 'js.js',
            'https://www.dropbox.com/static/api/2/dropins.js' => 'dropins.js',
            'https://apis.google.com/js/api.js' => 'api.js',
            'https://js.live.net/v7.2/OneDrive.js' => 'OneDrive.js',
            'https://js.live.net/v7.2/OneDrive2.js' => 'OneDrive2.js',
            'https://fonts.googleapis.com/css2?family=Roboto' => 'roboto-custom.css',
            'https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,400;0,700;1,400&display=swap' => 'roboto-custom.css',
            'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap' => 'roboto-custom.css',
            'https://ams.wpml.org/mini_app/localization/fa.json' => 'en.json',
            'https://ams.wpml.org/mini_app/localization/en.json' => 'en.json',
            'https://ams.wpml.org/mini_app/localization/ar.json' => 'ar.json',
            'https://ams.wpml.org/mini_app/localization/tr.json' => 'tr.json',
            'https://woodmart.xtemos.com/theme-settings-tooltips/change-product-image-attribute-click.mp4' => 'change-product-image-attribute-click.mp4',
            'https://fonts.googleapis.com/css?family=Great+Vibes%3A400' => 'GreatVibesCustom.css',
            'https://fonts.googleapis.com/css2?family=Open+Sans%3Awght%40300%3B400%3B600%3B700%3B800' => 'roboto-custom.css',
            'https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,500;1,14..32,500&family=Open+Sans:ital,wght@1,600&family=Roboto:wght@400;700&family=Roboto:ital,wght@1,500&family=Sora:wght@600&display=swap' => 'roboto-custom.css',
            'https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js' => 'jquery.mask.min.js',
            'https://cdn.jsdelivr.net/npm/apexcharts' => 'apexcharts.js',
            'https://ajax.googleapis.com/ajax/libs/webfont/1.6.26/webfont.js' => 'webfont.js',
            'https://yoast.com/shared-assets/scripts/wp-seo-premium-draft-js-plugins-source-2.0.0.min.js' => 'wp-seo-premium-draft-js-plugins-source-2.0.0.min.js',
            'https://s3.us-west-2.amazonaws.com/cdn.wpbakery.com/teasers/templatera-ico.1759390393.svg' => 'templatera-ico.1759390393.svg',
            'https://s3.us-west-2.amazonaws.com/cdn.wpbakery.com/teasers/easy-tables-ico.1759390492.svg' => 'easy-tables-ico.1759390492.svg',
            'https://s3.us-west-2.amazonaws.com/cdn.wpbakery.com/teasers/wpdatatables-ico.1759741271.png' => 'wpdatatables-ico.1759741271.png',
            'https://s3.us-west-2.amazonaws.com/cdn.wpbakery.com/teasers/amelia-ico.1759741346.png' => 'amelia-ico.1759741346.png',
            'https://s3.us-west-2.amazonaws.com/cdn.wpbakery.com/teasers/patchstack-ico.1760002013.png' => 'patchstack-ico.1760002013.png',
            'https://s3.us-west-2.amazonaws.com/cdn.wpbakery.com/teasers/dynamic-ooo-ico.1759414457.png' => 'dynamic-ooo-ico.1759414457.png',
            'https://s3.us-west-2.amazonaws.com/cdn.wpbakery.com/teasers/translatepress-plugin-ico.1760440172.svg' => 'translatepress-plugin-ico.1760440172.svg',
            'https://s3.us-west-2.amazonaws.com/cdn.wpbakery.com/teasers/nitropack-ico.1759415102.png' => 'nitropack-ico.1759415102.png',
            'https://fonts.googleapis.com/css?family=Work+Sans%3A400%2C600%7CUrbanist%3A400%2C600%2C800' => 'Work-Sans.css',
            'http://fonts.googleapis.com/css?family=Roboto:400%7CPoppins:600%7CLato:400&display=swap' => 'roboto-custom.css',
            'https://cdn.jsdelivr.net/npm/apexcharts@latest/dist/apexcharts.min.js' => 'apexcharts.min.js',
            'https://unpkg.com/libphonenumber-js@latest/examples.mobile.json' => 'examples.mobile.json',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css' => 'all.min.css',
        ];

        $rules = [];
        foreach ($map as $original => $replace) {
            $rules[] = ['original' => $original, 'replace' => $replace];
        }
        return $rules;
    }

    public static function get_settings() {
        $raw = get_option(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];

        // نسخه‌های قدیمی فقط کلید enabled داشتند که هر دو نوع مسدودسازی را روشن می‌کرد.
        if (
            isset($raw['enabled']) &&
            $raw['enabled'] === '1' &&
            !isset($raw['block_external_requests']) &&
            !isset($raw['block_domains'])
        ) {
            $raw['block_external_requests'] = '1';
            $raw['block_domains'] = '1';
        }

        return wp_parse_args($raw, self::default_settings());
    }

    private function flag($key) {
        return isset($this->settings[$key]) && $this->settings[$key] === '1';
    }

    private function is_any_feature_enabled() {
        return $this->flag('enabled')
            || $this->flag('block_external_requests')
            || $this->flag('block_domains')
            || $this->flag('block_external_http');
    }

    private function init() {
        // ۱) درخواست‌های سمت سرور
        if ($this->flag('block_domains') || $this->flag('block_external_http')) {
            add_filter('pre_http_request', [$this, 'block_http_requests'], 1, 3);
        }

        // ۲) فایل‌های صفحه (پیشخوان و سایت)
        if ($this->flag('enabled') || $this->flag('block_external_requests')) {
            add_filter('script_loader_src', [$this, 'replace_src'], 999);
            add_filter('style_loader_src', [$this, 'replace_src'], 999);
            add_action('init', [$this, 'start_output_buffer'], 0);
        }

        // ۳) منابع جانبی وردپرس که مستقیم به سرورهای خارجی وصل می‌شوند
        if ($this->flag('block_external_requests')) {
            add_filter('wp_resource_hints', [$this, 'filter_resource_hints'], 999, 2);
            add_filter('get_avatar_url', [$this, 'filter_avatar_url'], 999);
            add_action('init', [$this, 'disable_remote_emoji'], 1);
        }
    }

    /* ------------------------------------------------------------------
     * تشخیص میزبان
     * ---------------------------------------------------------------- */

    private static function host_of($url) {
        $url = trim((string) $url);
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return rtrim($host, '.');
    }

    /** دامنه‌ای که کاربر ممکن است با https:// یا مسیر وارد کرده باشد. */
    public static function clean_domain($value) {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }
        if (strpos($value, '/') !== false) {
            $host = self::host_of(strpos($value, '//') === false ? 'https://' . $value : $value);
            return $host;
        }
        return rtrim(ltrim($value, '*.'), '.');
    }

    private static function host_matches($host, $domain) {
        return $domain !== '' && ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain);
    }

    private function site_hosts() {
        if ($this->site_hosts === null) {
            $hosts = [];
            foreach ([get_option('home'), get_option('siteurl')] as $url) {
                $host = self::host_of($url);
                if ($host !== '') {
                    $hosts[$host] = true;
                    $hosts[preg_replace('/^www\./', '', $host)] = true;
                    $hosts['www.' . preg_replace('/^www\./', '', $host)] = true;
                }
            }
            if (!empty($_SERVER['HTTP_HOST'])) {
                $hosts[strtolower(preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']))] = true;
            }
            $hosts['localhost'] = true;
            $this->site_hosts = $hosts;
        }
        return $this->site_hosts;
    }

    private function is_local_host($host) {
        if ($host === '' || isset($this->site_hosts()[$host])) {
            return true;
        }
        // آی‌پی‌های داخلی/loopback (کرون وردپرس، loopback سلامت سایت، سرویس‌های داخلی)
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return substr($host, -6) === '.local' || substr($host, -10) === '.localhost';
    }

    /**
     * میزبان مجاز در فهرست سفید (برای حالت اینترانت و مسدودسازی فایل‌های صفحه).
     */
    private function is_allowed_host($host) {
        if ($this->is_local_host($host)) {
            return true;
        }

        // دامنه‌های ایرانی (.ir و .ایران) در اینترانت هم در دسترس‌اند.
        if ($this->flag('allow_ir_domains') && (substr($host, -3) === '.ir' || substr($host, -strlen('.ایران')) === '.ایران' || substr($host, -13) === '.xn--mgba3a4f16a')) {
            return true;
        }

        foreach ((array) $this->settings['allowed_domains'] as $allowed) {
            if (self::host_matches($host, self::clean_domain($allowed))) {
                return true;
            }
        }

        // کلیدواژه‌ها فقط روی نام میزبان بررسی می‌شوند (مثلاً sep.shaparak.ir یا idpay.ir)؛
        // بررسی کل آدرس باعث می‌شد هر فایلی که «pay» یا «sms» در مسیرش داشت مجاز شود.
        foreach ((array) $this->settings['allowed_keywords'] as $keyword) {
            $keyword = strtolower(trim((string) $keyword));
            if ($keyword !== '' && strpos($host, $keyword) !== false) {
                return true;
            }
        }

        // سرویس‌های خود کلادتارت (بررسی آپدیت و اتصال پلتفرم)
        foreach ([function_exists('cloudtart_support_update_api_url') ? cloudtart_support_update_api_url() : '', defined('CLOUDTART_CONNECTOR_API_URL') ? CLOUDTART_CONNECTOR_API_URL : ''] as $service) {
            $service_host = self::host_of($service);
            if ($service_host !== '' && $service_host === $host) {
                return true;
            }
        }

        // WP_ACCESSIBLE_HOSTS هسته‌ی وردپرس هم محترم شمرده می‌شود.
        if (defined('WP_ACCESSIBLE_HOSTS') && WP_ACCESSIBLE_HOSTS) {
            foreach (preg_split('/[\s,]+/', (string) WP_ACCESSIBLE_HOSTS) as $accessible) {
                $accessible = strtolower(trim($accessible));
                if ($accessible === '') {
                    continue;
                }
                if (strpos($accessible, '*') !== false) {
                    $pattern = '/^' . str_replace('\*', '.+', preg_quote($accessible, '/')) . '$/';
                    if (preg_match($pattern, $host)) {
                        return true;
                    }
                } elseif ($host === $accessible) {
                    return true;
                }
            }
        }

        return (bool) apply_filters('cloudtart_internal_cdn_allowed_host', false, $host);
    }

    private function is_blocklisted_host($host) {
        if (!$this->flag('block_domains')) {
            return false;
        }
        foreach ((array) $this->settings['blocked_domains'] as $blocked) {
            if (self::host_matches($host, self::clean_domain($blocked))) {
                return true;
            }
        }
        return false;
    }

    /** آیا منبع صفحه (اسکریپت، استایل، فونت ...) باید حذف شود؟ */
    private function should_block_asset($url) {
        $host = self::host_of($url);
        if ($host === '' || $this->is_local_host($host)) {
            return false;
        }
        if ($this->is_blocklisted_host($host)) {
            return true;
        }
        return $this->flag('block_external_requests') && !$this->is_allowed_host($host);
    }

    /* ------------------------------------------------------------------
     * ۱) درخواست‌های سمت سرور
     * ---------------------------------------------------------------- */

    public function block_http_requests($preempt, $args, $url) {
        if ($preempt !== false) {
            return $preempt;
        }

        $host = self::host_of($url);
        if ($host === '' || $this->is_local_host($host)) {
            return $preempt;
        }

        $block = $this->is_blocklisted_host($host)
            || ($this->flag('block_external_http') && !$this->is_allowed_host($host));

        if (!$block) {
            return $preempt;
        }

        $this->write_log_line('BLOCKED', 'HTTP', $url);

        return $this->empty_response($url, is_array($args) && !empty($args['filename']) ? $args['filename'] : null);
    }

    /**
     * پاسخ خالی فوری. کد 204 (به‌جای WP_Error) انتخاب شده تا وردپرس برای درخواست‌های
     * مسدودشده هشدار «An unexpected error occurred» ثبت نکند؛ کلید http_response هم
     * ساخته می‌شود تا کدی که به آن دسترسی دارد خطای مرگبار ندهد.
     */
    private function empty_response($url, $filename) {
        $response = [
            'headers' => [],
            'body' => '',
            'response' => [
                'code' => 204,
                'message' => 'No Content',
            ],
            'cookies' => [],
            'filename' => $filename,
        ];

        try {
            if (class_exists('\WpOrg\Requests\Response')) {
                $raw = new \WpOrg\Requests\Response();
            } elseif (class_exists('Requests_Response')) {
                $raw = new Requests_Response();
            } else {
                $raw = null;
            }
            if ($raw !== null && class_exists('WP_HTTP_Requests_Response')) {
                $raw->status_code = 204;
                $raw->success = true;
                $raw->url = (string) $url;
                $raw->body = '';
                $response['http_response'] = new WP_HTTP_Requests_Response($raw, (string) $filename);
                if (class_exists('\WpOrg\Requests\Utility\CaseInsensitiveDictionary')) {
                    $response['headers'] = new \WpOrg\Requests\Utility\CaseInsensitiveDictionary([]);
                } elseif (class_exists('Requests_Utility_CaseInsensitiveDictionary')) {
                    $response['headers'] = new Requests_Utility_CaseInsensitiveDictionary([]);
                }
            }
        } catch (Throwable $e) {
            unset($response['http_response']);
        }

        return $response;
    }

    /* ------------------------------------------------------------------
     * ۲) فایل‌های صفحه
     * ---------------------------------------------------------------- */

    /**
     * کلید مقایسه‌ی آدرس: بدون پروتکل، میزبان با حروف کوچک، کدگشایی‌شده و بدون پارامتر
     * ver که وردپرس به فایل‌های صف‌شده اضافه می‌کند. ترتیب بقیه‌ی پارامترها حفظ می‌شود
     * چون در Google Fonts پارامتر family تکرار می‌شود و ترتیبش معنا دارد.
     */
    private static function url_key($url, $with_query = true) {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES, 'UTF-8');
        if ($url === '') {
            return '';
        }
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }
        $url = preg_replace('/#.*$/', '', $url);
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return '';
        }

        $key = strtolower($parts['host']) . rawurldecode(isset($parts['path']) ? $parts['path'] : '/');

        if ($with_query && isset($parts['query']) && $parts['query'] !== '') {
            $params = [];
            foreach (explode('&', $parts['query']) as $pair) {
                if ($pair === '' || stripos($pair, 'ver=') === 0) {
                    continue;
                }
                $params[] = urldecode($pair); // "+" و "%20" هر دو به فاصله تبدیل می‌شوند
            }
            if ($params) {
                $key .= '?' . implode('&', $params);
            }
        }

        return $key;
    }

    private function replacement_map() {
        if ($this->replacement_map === null) {
            $map = ['full' => [], 'path' => []];
            $rules = isset($this->settings['replacements']) && is_array($this->settings['replacements']) ? $this->settings['replacements'] : [];
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $original = isset($rule['original']) ? $rule['original'] : '';
                $target = $this->resolve_replacement_url(isset($rule['replace']) ? $rule['replace'] : '');
                $full = self::url_key($original, true);
                if ($full === '' || $target === '') {
                    continue;
                }
                if (!isset($map['full'][$full])) {
                    $map['full'][$full] = $target;
                }
                // قانون بدون پارامتر، هر نسخه‌ای از همان فایل را پوشش می‌دهد (مثلاً ?ver=1.2).
                if (strpos($full, '?') === false && !isset($map['path'][$full])) {
                    $map['path'][$full] = $target;
                }
            }
            $this->replacement_map = $map;
        }
        return $this->replacement_map;
    }

    private function lookup_replacement($url) {
        if (!$this->flag('enabled')) {
            return false;
        }
        $map = $this->replacement_map();
        $full = self::url_key($url, true);
        if ($full !== '' && isset($map['full'][$full])) {
            return $map['full'][$full];
        }
        $path = self::url_key($url, false);
        if ($path !== '' && isset($map['path'][$path])) {
            return $map['path'][$path];
        }
        return false;
    }

    private function resolve_replacement_url($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value) || strpos($value, '//') === 0 || strpos($value, '/') === 0) {
            return $value;
        }

        if (strpos($value, 'bundle:') === 0) {
            return $this->get_bundled_asset_url(substr($value, 7));
        }

        if (strpos($value, 'upload:') === 0) {
            return $this->get_uploaded_asset_url(substr($value, 7));
        }

        $bundled_url = $this->get_bundled_asset_url($value);
        return $bundled_url !== '' ? $bundled_url : $this->get_uploaded_asset_url($value);
    }

    /**
     * نام فایل امن در پوشه‌ی دارایی‌ها.
     *
     * sanitize_file_name() وردپرس بخش‌های میانی نام را «پسوند مشکوک» می‌داند و به آن‌ها
     * _ اضافه می‌کند (clipboard.min.js ← clipboard.min_.js)؛ به همین دلیل هیچ‌کدام از
     * هدایت‌های فایل‌های .min.js/.min.css کار نمی‌کرد. مقادیری که قبلاً به این شکل
     * ذخیره شده‌اند هم اینجا اصلاح می‌شوند.
     *
     * @param string $dir پوشه‌ای که فایل در آن جست‌وجو می‌شود ('' = فقط اعتبارسنجی نام)
     */
    public static function safe_asset_name($filename, $dir = '') {
        $filename = basename(str_replace('\\', '/', trim((string) $filename)));
        if ($filename === '' || $filename[0] === '.' || strpos($filename, '..') !== false || !preg_match('/^[A-Za-z0-9._@()+-]{1,200}$/', $filename)) {
            return '';
        }
        $dir = rtrim((string) $dir, '/\\');
        if ($dir !== '' && !file_exists($dir . '/' . $filename) && strpos($filename, '_.') !== false) {
            $unmangled = str_replace('_.', '.', $filename);
            if (file_exists($dir . '/' . $unmangled)) {
                return $unmangled;
            }
        }
        return $filename;
    }

    private function get_bundled_asset_url($filename) {
        $dir = CLOUDTART_SUPPORT_DIR . 'assets/local-cdn-assets';
        $filename = self::safe_asset_name($filename, $dir);
        if ($filename === '' || !file_exists($dir . '/' . $filename)) {
            return '';
        }
        return CLOUDTART_SUPPORT_URL . 'assets/local-cdn-assets/' . rawurlencode($filename);
    }

    private function get_uploaded_asset_url($filename) {
        $upload_dir = wp_upload_dir(null, false);
        $filename = self::safe_asset_name($filename, trailingslashit($upload_dir['basedir']) . 'cloudtart-support-cdn');
        if ($filename === '') {
            return '';
        }
        if (!file_exists(trailingslashit($upload_dir['basedir']) . 'cloudtart-support-cdn/' . $filename)) {
            return '';
        }
        return trailingslashit($upload_dir['baseurl']) . 'cloudtart-support-cdn/' . rawurlencode($filename);
    }

    /** فایل‌های صف‌شده با wp_enqueue_script/style */
    public function replace_src($src) {
        if (empty($src) || !is_string($src)) {
            return $src;
        }

        $replacement = $this->lookup_replacement($src);
        if ($replacement !== false) {
            $this->write_log_line('REDIRECT', 'ENQUEUE', $src, $replacement);
            return $replacement;
        }

        if ($this->should_block_asset($src)) {
            $this->write_log_line('BLOCKED', 'ENQUEUE', $src);
            // src خالی: وردپرس اصلاً تگ را چاپ نمی‌کند (نه درخواستی، نه خطای کنسول).
            return '';
        }

        return $src;
    }

    public function start_output_buffer() {
        if (
            (defined('DOING_AJAX') && DOING_AJAX) ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (defined('DOING_CRON') && DOING_CRON) ||
            (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
            (defined('WP_CLI') && WP_CLI) ||
            (function_exists('wp_is_json_request') && wp_is_json_request()) ||
            (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD')
        ) {
            return;
        }

        // تخلیه با تکه‌های ۴ مگابایتی: اگر افزونه‌ای فایل حجیمی را بدون پاک‌کردن بافرها
        // بفرستد، کل آن در حافظه جمع نمی‌شود. خود وردپرس در پایان همه‌ی بافرها را تخلیه می‌کند.
        ob_start([$this, 'process_html'], self::BUFFER_CHUNK);
    }

    private function response_is_html($buffer) {
        foreach (array_reverse(headers_list()) as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return stripos($header, 'html') !== false;
            }
        }
        // بدون هدر Content-Type خروجی PHP به‌صورت پیش‌فرض HTML است؛ ولی فقط محتوای شبیه HTML پردازش می‌شود.
        return stripos($buffer, '<script') !== false || stripos($buffer, '<link') !== false || stripos($buffer, '<style') !== false;
    }

    public function process_html($html, $phase = 0) {
        if (!is_string($html) || $html === '' || !$this->response_is_html($html)) {
            return $html;
        }

        if (stripos($html, '<script') === false && stripos($html, '<link') === false && stripos($html, '@import') === false) {
            return $html;
        }

        $result = preg_replace_callback(
            '/<(script|link)\b([^>]*?)\s(src|href)\s*=\s*(["\'])((?:https?:)?\/\/[^"\']+)\4([^>]*)>/i',
            [$this, 'rewrite_tag'],
            $html
        );
        if (is_string($result)) {
            $html = $result;
        }

        if (stripos($html, '@import') !== false) {
            $result = preg_replace_callback(
                '/@import\s+(?:url\(\s*(["\']?)([^"\')\s]+)\1\s*\)|(["\'])([^"\']+)\3)([^;]*);/i',
                [$this, 'rewrite_import'],
                $html
            );
            if (is_string($result)) {
                $html = $result;
            }
        }

        // SRI برای فایل‌هایی که به نسخه‌ی محلی هدایت شده‌اند اعتبار ندارد؛ integrity
        // نامطابق، مرورگر را به اجرا نکردن فایل وادار می‌کرد.
        if (stripos($html, 'integrity') !== false) {
            $result = preg_replace_callback(
                '/<(script|link)\b[^>]*\sintegrity\s*=\s*(["\'])[^"\']*\2[^>]*>/i',
                [$this, 'strip_local_integrity'],
                $html
            );
            if (is_string($result)) {
                $html = $result;
            }
        }

        return $html;
    }

    public function rewrite_tag($m) {
        $tag = strtolower($m[1]);
        $before = $m[2];
        $attr = $m[3];
        $quote = $m[4];
        $url = $m[5];
        $after = $m[6];

        // فقط src اسکریپت و href لینک
        if (($tag === 'script' && strtolower($attr) !== 'src') || ($tag === 'link' && strtolower($attr) !== 'href')) {
            return $m[0];
        }

        $rel = '';
        if ($tag === 'link') {
            if (!preg_match('/\srel\s*=\s*(["\']?)([^"\'>]+)\1/i', ' ' . $before . ' ' . $after, $rm)) {
                return $m[0];
            }
            $rel = strtolower(trim($rm[2]));
            // canonical، alternate، profile و ... فقط اشاره‌گرند و باید دست‌نخورده بمانند.
            if (!preg_match('/\b(stylesheet|preload|modulepreload|prefetch|preconnect|dns-prefetch)\b/', $rel)) {
                return $m[0];
            }
        }

        $replacement = $this->lookup_replacement($url);
        if ($replacement !== false && !preg_match('/\b(preconnect|dns-prefetch)\b/', $rel)) {
            $this->write_log_line('REDIRECT', 'HTML', $url, $replacement);
            return '<' . $m[1] . $before . ' ' . $attr . '=' . $quote . esc_url($replacement) . $quote . $after . '>';
        }

        if (!$this->should_block_asset($url)) {
            return $m[0];
        }

        $this->write_log_line('BLOCKED', 'HTML', $url);

        // integrity روی محتوای خالی شکست می‌خورد و خطای بی‌مورد در کنسول می‌سازد.
        $strip = '/\s(?:integrity|crossorigin)(?:\s*=\s*(["\'])[^"\']*\1)?(?=\s|$|\/)/i';
        $before = preg_replace($strip, '', $before);
        $after = preg_replace($strip, '', $after);

        if ($tag === 'script') {
            // src خالیِ data: هیچ درخواستی نمی‌سازد و async/defer و onload را حفظ می‌کند.
            return '<script' . $before . ' src=' . $quote . 'data:text/javascript,' . $quote . ' data-ct-blocked=' . $quote . esc_attr($url) . $quote . $after . '>';
        }

        if (preg_match('/\bstylesheet\b/', $rel)) {
            return '<link' . $before . ' href=' . $quote . 'data:text/css,' . $quote . ' data-ct-blocked=' . $quote . esc_attr($url) . $quote . $after . '>';
        }

        // preconnect / dns-prefetch / preload / prefetch به میزبان مسدود حذف می‌شوند.
        return '';
    }

    public function rewrite_import($m) {
        $url = isset($m[2]) && $m[2] !== '' ? $m[2] : (isset($m[4]) ? $m[4] : '');
        $media = isset($m[5]) ? $m[5] : '';
        if (!preg_match('#^(?:https?:)?//#i', $url)) {
            return $m[0];
        }
        $replacement = $this->lookup_replacement($url);
        if ($replacement !== false) {
            $this->write_log_line('REDIRECT', 'IMPORT', $url, $replacement);
            return '@import url("' . esc_url($replacement) . '")' . $media . ';';
        }
        if ($this->should_block_asset($url)) {
            $this->write_log_line('BLOCKED', 'IMPORT', $url);
            return '/* cloudtart: blocked @import ' . str_replace('*/', '', $url) . ' */';
        }
        return $m[0];
    }

    public function strip_local_integrity($m) {
        if (!preg_match('/\s(?:src|href)\s*=\s*(["\'])([^"\']+)\1/i', $m[0], $um) || !$this->is_local_asset_url($um[2])) {
            return $m[0];
        }
        return preg_replace('/\s(?:integrity|crossorigin)(?:\s*=\s*(["\'])[^"\']*\1)?(?=[\s>\/])/i', '', $m[0]);
    }

    private function is_local_asset_url($url) {
        if ($this->local_bases === null) {
            $upload_dir = wp_upload_dir(null, false);
            $this->local_bases = [
                CLOUDTART_SUPPORT_URL . 'assets/local-cdn-assets/',
                trailingslashit($upload_dir['baseurl']) . 'cloudtart-support-cdn/',
            ];
        }
        $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
        foreach ($this->local_bases as $base) {
            if (strpos($url, $base) === 0 || strpos(preg_replace('#^https?:#i', '', $url), preg_replace('#^https?:#i', '', $base)) === 0) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------
     * ۳) منابع جانبی وردپرس
     * ---------------------------------------------------------------- */

    /** dns-prefetch / preconnect وردپرس (مثلاً s.w.org) به میزبان‌های مسدود حذف می‌شوند. */
    public function filter_resource_hints($urls, $relation_type) {
        $clean = [];
        foreach ((array) $urls as $item) {
            $href = is_array($item) ? (isset($item['href']) ? $item['href'] : '') : $item;
            if ($href !== '' && $this->should_block_asset(strpos((string) $href, '//') === false && strpos((string) $href, ':') === false ? '//' . $href : $href)) {
                continue;
            }
            $clean[] = $item;
        }
        return $clean;
    }

    /** تصاویر آواتار از gravatar.com: به‌جای آن تصویر محلی نمایش داده می‌شود. */
    public function filter_avatar_url($url) {
        if (!is_string($url) || $url === '' || !$this->should_block_asset($url)) {
            return $url;
        }
        return CLOUDTART_SUPPORT_URL . 'assets/img/avatar-placeholder.svg';
    }

    /**
     * اسکریپت ایموجی وردپرس برای مرورگرهای بدون پشتیبانی کامل، تصاویر ایموجی را از
     * s.w.org می‌گیرد. در این حالت از ایموجی خود مرورگر استفاده می‌شود.
     */
    public function disable_remote_emoji() {
        if (!$this->should_block_asset('https://s.w.org/images/core/emoji/')) {
            return;
        }
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('embed_head', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
        remove_action('admin_enqueue_scripts', 'wp_enqueue_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('emoji_svg_url', '__return_false');
    }

    /* ------------------------------------------------------------------
     * لاگ
     * ---------------------------------------------------------------- */

    private function write_log_line($action, $type, $from, $to = '') {
        if (!$this->flag('log_blocked_requests')) {
            return;
        }

        $from = trim((string) $from);
        if ($from === '') {
            return;
        }

        $signature = md5($action . '|' . $type . '|' . $from . '|' . $to);
        if (isset($this->logged[$signature]) || count($this->logged) > 200) {
            return;
        }
        $this->logged[$signature] = true;

        $log_file_info = $this->get_log_file_info();
        if (!is_dir($log_file_info['basedir'])) {
            wp_mkdir_p($log_file_info['basedir']);
        }

        $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . strtoupper($action) . ' (' . strtoupper($type) . '): ' . $from;
        if ($to !== '') {
            $line .= ' => ' . $to;
        }

        @file_put_contents($log_file_info['file_path'], $line . PHP_EOL, FILE_APPEND | LOCK_EX); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    private function get_log_file_info() {
        if (function_exists('cloudtart_support_get_internal_cdn_log_file')) {
            return cloudtart_support_get_internal_cdn_log_file();
        }

        return [
            'basedir' => WP_CONTENT_DIR,
            'file_path' => WP_CONTENT_DIR . '/cdn-replacer-blocked.log',
        ];
    }

    public function migrate_legacy_log_file() {
        $legacy_log_file = WP_CONTENT_DIR . '/cdn-replacer-blocked.log';
        if (!is_file($legacy_log_file)) {
            return;
        }

        $log_file_info = $this->get_log_file_info();
        $target_file = $log_file_info['file_path'];
        if ($legacy_log_file === $target_file || is_file($target_file)) {
            return;
        }

        if (!is_dir($log_file_info['basedir'])) {
            wp_mkdir_p($log_file_info['basedir']);
        }

        if (!@rename($legacy_log_file, $target_file)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $contents = @file_get_contents($legacy_log_file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ($contents !== false && @file_put_contents($target_file, $contents) !== false) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                @unlink($legacy_log_file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
    }
}
