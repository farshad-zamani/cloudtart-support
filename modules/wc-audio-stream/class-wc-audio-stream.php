<?php
/**
 * ماژول پخش آنلاین فایل‌های صوتی ووکامرس
 *
 * فایل‌های صوتی خریداری‌شده را در «دانلودها»ی حساب کاربری و صفحه‌ی مشاهده‌ی سفارش
 * به‌جای لینک دانلود با دکمه‌ی پخش نمایش می‌دهد، دانلود مستقیم آن‌ها را (در صورت
 * تنظیم) مسدود می‌کند و فایل را از طریق یک endpoint امضاشده و موقت استریم می‌کند.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CloudTart_Support_WC_Audio_Stream')) {
    class CloudTart_Support_WC_Audio_Stream {

        const AJAX_ACTION = 'cloudtart_audio_stream';

        /** لینک استریم پس از این مدت باطل می‌شود و با بازکردن دوباره‌ی صفحه تولید می‌شود. */
        const TOKEN_TTL = 4 * HOUR_IN_SECONDS;

        /** اندازه‌ی هر تکه هنگام ارسال فایل محلی (۱ مگابایت). */
        const CHUNK_SIZE = 1048576;

        private $allow_download = false;
        private $show_player = true;
        private $theme = 'dark';

        private $tracks_rendered = 0;
        private $assets_enqueued = false;

        public function __construct(array $plugins_settings = []) {
            if (!class_exists('WooCommerce')) {
                return;
            }

            $this->allow_download = isset($plugins_settings['wc_audio_allow_download']) && $plugins_settings['wc_audio_allow_download'] === '1';
            $this->show_player = !isset($plugins_settings['wc_audio_show_player']) || $plugins_settings['wc_audio_show_player'] === '1';
            $this->theme = (isset($plugins_settings['wc_audio_player_theme']) && $plugins_settings['wc_audio_player_theme'] === 'light') ? 'light' : 'dark';

            // «دانلودها»ی حساب کاربری، صفحه‌ی مشاهده‌ی سفارش و صفحه‌ی تشکر همگی همین هوک را صدا می‌زنند.
            add_action('woocommerce_account_downloads_column_download-file', [$this, 'render_download_cell']);

            add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'stream']);
            add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'stream']);

            add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
            // باید قبل از wp_print_footer_scripts (اولویت ۲۰) چاپ شود؛ وگرنه اسکریپت پلیر پیش از
            // ساخته‌شدن #ct-audio-player اجرا می‌شد، آن را پیدا نمی‌کرد و هیچ دکمه‌ای کار نمی‌کرد.
            add_action('wp_footer', [$this, 'render_player'], 5);

            if (!$this->allow_download) {
                add_action('woocommerce_download_product', [$this, 'block_direct_download'], 1, 6);
                add_action('woocommerce_email_downloads_column_download-file', [$this, 'render_email_cell'], 10, 2);
            }
        }

        /* ------------------------------------------------------------------
         * تشخیص فایل صوتی
         * ---------------------------------------------------------------- */

        public static function get_audio_mime_types() {
            return apply_filters('cloudtart_audio_stream_mime_types', [
                'mp3'  => 'audio/mpeg',
                'm4a'  => 'audio/mp4',
                'aac'  => 'audio/aac',
                'wav'  => 'audio/wav',
                'ogg'  => 'audio/ogg',
                'oga'  => 'audio/ogg',
                'opus' => 'audio/ogg',
                'flac' => 'audio/flac',
                'weba' => 'audio/webm',
                'wma'  => 'audio/x-ms-wma',
                'aiff' => 'audio/aiff',
                'aif'  => 'audio/aiff',
            ]);
        }

        private function get_extension($path) {
            $path = (string) $path;
            $path = preg_replace('/[?#].*$/', '', $path);

            return strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }

        private function is_audio_path($path) {
            $extension = $this->get_extension($path);

            return $extension !== '' && array_key_exists($extension, self::get_audio_mime_types());
        }

        private function is_audio_download(array $download) {
            return isset($download['file']['file']) && $this->is_audio_path($download['file']['file']);
        }

        /* ------------------------------------------------------------------
         * لینک استریم امضاشده
         * ---------------------------------------------------------------- */

        private function sign($payload) {
            return substr(hash_hmac('sha256', $payload, wp_salt('auth')), 0, 40);
        }

        private function build_stream_url(array $download) {
            if (empty($download['order_id']) || empty($download['product_id']) || empty($download['download_id'])) {
                return '';
            }

            $expires = time() + self::TOKEN_TTL;
            $payload = implode('|', [
                (int) $download['order_id'],
                (int) $download['product_id'],
                (string) $download['download_id'],
                $expires,
            ]);

            return add_query_arg(
                [
                    'action' => self::AJAX_ACTION,
                    'o' => (int) $download['order_id'],
                    'p' => (int) $download['product_id'],
                    'd' => rawurlencode((string) $download['download_id']),
                    'e' => $expires,
                    's' => $this->sign($payload),
                ],
                admin_url('admin-ajax.php')
            );
        }

        /* ------------------------------------------------------------------
         * خروجی در جدول دانلودها
         * ---------------------------------------------------------------- */

        private function default_download_link(array $download) {
            return sprintf(
                '<a href="%1$s" class="woocommerce-MyAccount-downloads-file button alt">%2$s</a>',
                esc_url(isset($download['download_url']) ? $download['download_url'] : ''),
                esc_html(isset($download['download_name']) ? $download['download_name'] : '')
            );
        }

        public function render_download_cell($download) {
            $download = is_array($download) ? $download : [];

            if (is_admin() || !$this->is_audio_download($download)) {
                echo $this->default_download_link($download); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return;
            }

            $stream_url = $this->build_stream_url($download);
            if ($stream_url === '') {
                echo $this->default_download_link($download); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return;
            }

            $this->tracks_rendered++;
            $this->enqueue_assets();

            $title = isset($download['download_name']) ? $download['download_name'] : '';
            $product = isset($download['product_name']) ? $download['product_name'] : '';

            echo '<div class="ct-audio-track">';

            if ($this->show_player) {
                printf(
                    '<button type="button" class="ct-audio-play" data-src="%1$s" data-title="%2$s" data-product="%3$s" data-key="%8$s" aria-label="%4$s" aria-pressed="false">'
                    . '<span class="ct-audio-play__icon" aria-hidden="true">%5$s%6$s</span>'
                    . '<span class="ct-audio-play__label">%7$s</span>'
                    . '</button>',
                    esc_url($stream_url),
                    esc_attr($title),
                    esc_attr($product),
                    /* translators: %s: track title */
                    esc_attr(sprintf(__('Play %s', 'cloudtart-support'), $title)),
                    $this->icon('play'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    $this->icon('pause'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    esc_html($title),
                    // یک فایل که در چند سفارش خریده شده فقط یک‌بار در لیست پخش می‌آید.
                    esc_attr((int) $download['product_id'] . ':' . (string) $download['download_id'])
                );
            } else {
                printf(
                    '<span class="ct-audio-track__title">%1$s</span>'
                    . '<audio class="ct-audio-inline" controls controlslist="nodownload noplaybackrate" preload="none" src="%2$s"></audio>',
                    esc_html($title),
                    esc_url($stream_url)
                );
            }

            if ($this->allow_download) {
                printf(
                    '<a href="%1$s" class="ct-audio-download" title="%2$s" aria-label="%2$s">%3$s</a>',
                    esc_url($download['download_url']),
                    esc_attr__('Download', 'cloudtart-support'),
                    $this->icon('download') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                );
            }

            echo '</div>';
        }

        /**
         * در ایمیل‌ها لینک دانلود فایل صوتی به‌جای لینک مستقیم، به صفحه‌ی دانلودهای حساب اشاره می‌کند.
         */
        public function render_email_cell($download, $plain_text = false) {
            $download = is_array($download) ? $download : [];
            $name = isset($download['download_name']) ? $download['download_name'] : '';

            if (!$this->is_audio_download($download)) {
                if ($plain_text) {
                    echo esc_html($name) . ' - ' . esc_url($download['download_url']);
                } else {
                    echo $this->default_download_link($download); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                return;
            }

            $account_url = wc_get_account_endpoint_url('downloads');
            $hint = __('Listen online in your account', 'cloudtart-support');

            if ($plain_text) {
                echo esc_html($name) . ' - ' . esc_html($hint) . ': ' . esc_url($account_url);
                return;
            }

            printf(
                '<a href="%1$s" class="woocommerce-MyAccount-downloads-file button alt">%2$s</a><br><small>%3$s</small>',
                esc_url($account_url),
                esc_html($name),
                esc_html($hint)
            );
        }

        /**
         * درخواست دانلود مستقیم ووکامرس برای فایل‌های صوتی را قبل از ارسال فایل متوقف می‌کند.
         */
        public function block_direct_download($email, $order_key, $product_id, $user_id, $download_id, $order_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                return;
            }

            $file = $product->get_file($download_id);
            if (!$file || !$this->is_audio_path($file->get_file())) {
                return;
            }

            $account_url = wc_get_account_endpoint_url('downloads');

            wp_die(
                '<p>' . esc_html__('This audio file is available for online listening only and cannot be downloaded.', 'cloudtart-support') . '</p>'
                . '<p><a class="button" href="' . esc_url($account_url) . '">' . esc_html__('Open my downloads', 'cloudtart-support') . '</a></p>',
                esc_html__('Streaming only', 'cloudtart-support'),
                ['response' => 403]
            );
        }

        /* ------------------------------------------------------------------
         * استریم
         * ---------------------------------------------------------------- */

        private function deny($message, $status = 403) {
            wp_die(esc_html($message), esc_html__('Streaming error', 'cloudtart-support'), ['response' => $status]);
        }

        public function stream() {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- امضای HMAC نقش nonce را دارد.
            $order_id = isset($_GET['o']) ? absint($_GET['o']) : 0;
            $product_id = isset($_GET['p']) ? absint($_GET['p']) : 0;
            $download_id = isset($_GET['d']) ? wc_clean(wp_unslash($_GET['d'])) : '';
            $expires = isset($_GET['e']) ? absint($_GET['e']) : 0;
            $signature = isset($_GET['s']) ? (string) wp_unslash($_GET['s']) : '';
            // phpcs:enable

            if (!$order_id || !$product_id || $download_id === '' || !$expires || $signature === '') {
                $this->deny(__('Invalid streaming link.', 'cloudtart-support'), 400);
            }

            if ($expires < time()) {
                $this->deny(__('This streaming link has expired. Please reload the page.', 'cloudtart-support'));
            }

            $payload = implode('|', [$order_id, $product_id, $download_id, $expires]);
            if (!hash_equals($this->sign($payload), $signature)) {
                $this->deny(__('Invalid streaming link.', 'cloudtart-support'));
            }

            $order = wc_get_order($order_id);
            if (!$order || !$order->is_download_permitted()) {
                $this->deny(__('This order does not allow access to its files.', 'cloudtart-support'));
            }

            $product = wc_get_product($product_id);
            if (!$product || !$product->has_file($download_id)) {
                $this->deny(__('File not found.', 'cloudtart-support'), 404);
            }

            $file = $product->get_file($download_id);
            if (!$file || !$file->get_enabled() || !$this->is_audio_path($file->get_file())) {
                $this->deny(__('File not found.', 'cloudtart-support'), 404);
            }

            // مجوز دانلود هنوز باید برای این سفارش/محصول/فایل وجود داشته باشد.
            $data_store = WC_Data_Store::load('customer-download');
            $permission_ids = $data_store->get_downloads([
                'order_id' => $order_id,
                'product_id' => $product_id,
                'download_id' => $download_id,
                'limit' => 1,
                'return' => 'ids',
            ]);

            if (empty($permission_ids)) {
                $this->deny(__('You do not have access to this file.', 'cloudtart-support'));
            }

            $permission = new WC_Customer_Download(current($permission_ids));
            $access_expires = $permission->get_access_expires();
            if (!is_null($access_expires) && $access_expires->getTimestamp() < strtotime('midnight', current_time('timestamp', true))) {
                $this->deny(__('Access to this file has expired.', 'cloudtart-support'));
            }

            if ('yes' === get_option('woocommerce_downloads_require_login')) {
                if (!is_user_logged_in()) {
                    $this->deny(__('Please log in to listen to this file.', 'cloudtart-support'), 401);
                }

                if ($order->get_customer_id() && (int) $order->get_customer_id() !== get_current_user_id()) {
                    $this->deny(__('You do not have access to this file.', 'cloudtart-support'));
                }
            } elseif (is_user_logged_in() && $order->get_customer_id() && (int) $order->get_customer_id() !== get_current_user_id()) {
                $this->deny(__('You do not have access to this file.', 'cloudtart-support'));
            }

            $file_path = apply_filters(
                'woocommerce_download_product_filepath',
                $product->get_file_download_path($download_id),
                $order->get_billing_email(),
                $order,
                $product,
                $permission
            );

            $parsed = WC_Download_Handler::parse_file_path($file_path);
            $mime_types = self::get_audio_mime_types();
            $mime = $mime_types[$this->get_extension($parsed['file_path'])] ?? 'application/octet-stream';
            $filename = sanitize_file_name(basename(preg_replace('/[?#].*$/', '', $parsed['file_path'])));

            if (!empty($parsed['remote_file'])) {
                $this->proxy_remote_file($parsed['file_path'], $mime, $filename);
            }

            $this->serve_local_file($parsed['file_path'], $mime, $filename);
        }

        private function prepare_streaming_output() {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            if (function_exists('wc_set_time_limit')) {
                wc_set_time_limit(0);
            } else {
                @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }

            @ini_set('zlib.output_compression', 'Off'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            ignore_user_abort(false);

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }

        private function send_common_headers($mime, $filename) {
            nocache_headers();
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
            header('Accept-Ranges: bytes');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: private, no-store, max-age=0');
        }

        /**
         * فایل محلی را با پشتیبانی از Range (برای جلو/عقب‌بردن پخش) ارسال می‌کند.
         */
        private function serve_local_file($path, $mime, $filename) {
            if (!is_file($path) || !is_readable($path)) {
                $this->deny(__('File not found.', 'cloudtart-support'), 404);
            }

            $size = filesize($path);
            $start = 0;
            $end = $size - 1;
            $status = 200;

            if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $range)) {
                if ($range[1] !== '' || $range[2] !== '') {
                    if ($range[1] === '') {
                        $start = max(0, $size - (int) $range[2]);
                    } else {
                        $start = (int) $range[1];
                        if ($range[2] !== '') {
                            $end = min((int) $range[2], $size - 1);
                        }
                    }

                    if ($start > $end || $start >= $size) {
                        $this->prepare_streaming_output();
                        status_header(416);
                        header('Content-Range: bytes */' . $size);
                        exit;
                    }

                    $status = 206;
                }
            }

            $length = $end - $start + 1;

            $this->prepare_streaming_output();
            status_header($status);
            $this->send_common_headers($mime, $filename);
            header('Content-Length: ' . $length);
            if ($status === 206) {
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            }

            if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD') {
                exit;
            }

            $handle = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if (!$handle) {
                $this->deny(__('File not found.', 'cloudtart-support'), 404);
            }

            fseek($handle, $start);
            $remaining = $length;

            while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
                $chunk = fread($handle, min(self::CHUNK_SIZE, $remaining)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $remaining -= strlen($chunk);
                flush();
            }

            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            exit;
        }

        /**
         * فایل راه‌دور را از طریق cURL عبور می‌دهد تا آدرس اصلی برای کاربر فاش نشود.
         * اگر cURL در دسترس نباشد، مثل خود ووکامرس به آدرس اصلی هدایت می‌کند.
         */
        private function proxy_remote_file($url, $mime, $filename) {
            if (!function_exists('curl_init')) {
                wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
                exit;
            }

            $this->prepare_streaming_output();

            $request_headers = ['Accept: */*'];
            if (!empty($_SERVER['HTTP_RANGE'])) {
                $request_headers[] = 'Range: ' . sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE']));
            }

            $headers_sent = false;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $request_headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'CloudTart-Support/' . (defined('CLOUDTART_SUPPORT_VERSION') ? CLOUDTART_SUPPORT_VERSION : '1.0'),
                CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$headers_sent, $mime, $filename) {
                    $trimmed = trim($line);

                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $m)) {
                        $code = (int) $m[1];
                        if ($code >= 400) {
                            $this->deny(__('File not found.', 'cloudtart-support'), 404);
                        }
                        if ($code === 200 || $code === 206) {
                            status_header($code);
                            $this->send_common_headers($mime, $filename);
                            $headers_sent = true;
                        }
                    } elseif ($headers_sent && preg_match('/^(Content-Length|Content-Range):/i', $trimmed)) {
                        header($trimmed);
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function($ch, $data) {
                    echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    flush();

                    return connection_aborted() ? 0 : strlen($data);
                },
            ]);

            curl_exec($ch);
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch); // از PHP 8 خودکار آزاد می‌شود؛ curl_close در PHP 8.5 منسوخ است
            }
            exit;
        }

        /* ------------------------------------------------------------------
         * پلیر و دارایی‌ها
         * ---------------------------------------------------------------- */

        public function maybe_enqueue_assets() {
            if ((function_exists('is_account_page') && is_account_page()) || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received'))) {
                $this->enqueue_assets();
            }
        }

        private function enqueue_assets() {
            if ($this->assets_enqueued) {
                return;
            }
            $this->assets_enqueued = true;

            wp_enqueue_style(
                'cloudtart-audio-player',
                CLOUDTART_SUPPORT_URL . 'assets/css/audio-player.css',
                [],
                CLOUDTART_SUPPORT_VERSION
            );

            wp_enqueue_script(
                'cloudtart-audio-player',
                CLOUDTART_SUPPORT_URL . 'assets/js/audio-player.js',
                [],
                CLOUDTART_SUPPORT_VERSION,
                true
            );

            wp_localize_script('cloudtart-audio-player', 'cloudtartAudioPlayer', [
                'theme' => $this->theme,
                'i18n' => [
                    'play' => __('Play', 'cloudtart-support'),
                    'pause' => __('Pause', 'cloudtart-support'),
                    'playTrack' => __('Play %s', 'cloudtart-support'),
                    'pauseTrack' => __('Pause %s', 'cloudtart-support'),
                    'error' => __('This track could not be played. Please reload the page and try again.', 'cloudtart-support'),
                    'nowPlaying' => __('Now playing', 'cloudtart-support'),
                    'openPlayer' => __('Open audio player', 'cloudtart-support'),
                    'trackCount' => __('%d tracks', 'cloudtart-support'),
                ],
            ]);
        }

        public function render_player() {
            if (!$this->show_player || $this->tracks_rendered === 0) {
                return;
            }
            ?>
            <div id="ct-audio-player" class="ct-audio-player" data-theme="<?php echo esc_attr($this->theme); ?>" role="region" aria-label="<?php echo esc_attr__('Audio player', 'cloudtart-support'); ?>" tabindex="0" hidden>
                <div class="ct-ap-main">
                    <div class="ct-ap-art" aria-hidden="true">
                        <?php echo $this->icon('note'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <span class="ct-ap-eq"><i></i><i></i><i></i><i></i></span>
                    </div>

                    <div class="ct-ap-meta">
                        <div class="ct-ap-title" aria-live="polite">&nbsp;</div>
                        <div class="ct-ap-product">&nbsp;</div>
                    </div>

                    <div class="ct-ap-controls">
                        <button type="button" class="ct-ap-btn ct-ap-prev" aria-label="<?php echo esc_attr__('Previous track', 'cloudtart-support'); ?>"><?php echo $this->icon('prev'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                        <button type="button" class="ct-ap-btn ct-ap-toggle" aria-label="<?php echo esc_attr__('Play', 'cloudtart-support'); ?>">
                            <?php echo $this->icon('play') . $this->icon('pause'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </button>
                        <button type="button" class="ct-ap-btn ct-ap-next" aria-label="<?php echo esc_attr__('Next track', 'cloudtart-support'); ?>"><?php echo $this->icon('next'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                    </div>

                    <div class="ct-ap-progress">
                        <span class="ct-ap-time ct-ap-time-current">0:00</span>
                        <div class="ct-ap-bar">
                            <div class="ct-ap-buffer"></div>
                            <div class="ct-ap-fill"></div>
                            <input type="range" class="ct-ap-seek" min="0" max="1000" value="0" step="1" aria-label="<?php echo esc_attr__('Seek', 'cloudtart-support'); ?>">
                        </div>
                        <span class="ct-ap-time ct-ap-time-duration">0:00</span>
                    </div>

                    <div class="ct-ap-extra">
                        <div class="ct-ap-volume">
                            <button type="button" class="ct-ap-btn ct-ap-mute" aria-label="<?php echo esc_attr__('Mute', 'cloudtart-support'); ?>"><?php echo $this->icon('volume') . $this->icon('muted'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                            <input type="range" class="ct-ap-volume-slider" min="0" max="100" value="100" aria-label="<?php echo esc_attr__('Volume', 'cloudtart-support'); ?>">
                        </div>
                        <button type="button" class="ct-ap-btn ct-ap-list-toggle" aria-label="<?php echo esc_attr__('Playlist', 'cloudtart-support'); ?>" aria-expanded="false"><?php echo $this->icon('list'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="ct-ap-count"></span></button>
                        <?php /* کوچک کردن پلیر (نه توقف پخش): پلیر به دکمه‌ی شناور پایین صفحه تبدیل می‌شود. */ ?>
                        <button type="button" class="ct-ap-btn ct-ap-close" aria-label="<?php echo esc_attr__('Minimize player', 'cloudtart-support'); ?>" title="<?php echo esc_attr__('Minimize player', 'cloudtart-support'); ?>"><?php echo $this->icon('minimize'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                    </div>
                </div>

                <div class="ct-ap-message" role="alert" hidden></div>
                <ol class="ct-ap-playlist" hidden></ol>

                <audio preload="metadata" controlslist="nodownload noplaybackrate" disableremoteplayback></audio>
            </div>

            <button type="button" id="ct-audio-mini" class="ct-audio-mini" data-theme="<?php echo esc_attr($this->theme); ?>" aria-label="<?php echo esc_attr__('Open audio player', 'cloudtart-support'); ?>" title="<?php echo esc_attr__('Open audio player', 'cloudtart-support'); ?>" hidden>
                <span class="ct-am-ring" aria-hidden="true"></span>
                <span class="ct-am-face" aria-hidden="true">
                    <?php echo $this->icon('note'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <span class="ct-am-eq"><i></i><i></i><i></i><i></i></span>
                </span>
            </button>
            <?php
        }

        private function icon($name) {
            $paths = [
                'play' => '<path d="M8 5.5v13l11-6.5-11-6.5Z"/>',
                'pause' => '<path d="M7 5h4v14H7zM13 5h4v14h-4z"/>',
                'prev' => '<path d="M6 5h2v14H6zM18 5.5v13l-9-6.5 9-6.5Z"/>',
                'next' => '<path d="M16 5h2v14h-2zM6 5.5v13l9-6.5-9-6.5Z"/>',
                'volume' => '<path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5H4Z"/><path d="M15.5 8.5a4.5 4.5 0 0 1 0 7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M18 6a8 8 0 0 1 0 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
                'muted' => '<path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5H4Z"/><path d="M15.5 9.5l5 5M20.5 9.5l-5 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
                'list' => '<path d="M4 6.5h10M4 12h10M4 17.5h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M17 10.5v7.2a2.2 2.2 0 1 1-1.5-2.1V8l4.5-1.2v2.5L17 10.5Z"/>',
                'close' => '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
                'minimize' => '<path d="M6.5 9.5l5.5 5.5 5.5-5.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
                'download' => '<path d="M12 4v10m0 0l-4-4m4 4l4-4M5 17.5v1A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
                'note' => '<path d="M10 17.5a3 3 0 1 1-2-2.83V5.8l10-2.5v11.2a3 3 0 1 1-2-2.83V7.1l-6 1.5v8.9Z"/>',
            ];

            if (!isset($paths[$name])) {
                return '';
            }

            // width/height صریح: اگر CSS قالب اندازه را به هم بزند، آیکون باز هم دیده می‌شود.
            return '<svg class="ct-icon ct-icon-' . esc_attr($name) . '" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">' . $paths[$name] . '</svg>';
        }
    }
}
