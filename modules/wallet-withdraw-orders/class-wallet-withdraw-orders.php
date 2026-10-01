<?php

if (!defined('ABSPATH')) {
    exit('Direct access is not allowed.');
}

if (!class_exists('CloudTart_Support_Wallet_Withdraw_Orders')) {
    class CloudTart_Support_Wallet_Withdraw_Orders {
        const STATUS_REQUEST = 'wc-fsww-withdraw-req';
        const STATUS_PAID = 'wc-fsww-paid-out';
        const STATUS_REJECTED = 'wc-fsww-rejected';
        const META_REQUEST_ID = '_ct_wallet_withdraw_request_id';
        const META_PROCESSED = '_ct_wallet_withdraw_processed';
        const META_FEE = '_ct_wallet_withdraw_fee';
        const META_PAYMENT_METHOD = '_ct_wallet_withdraw_method';
        const META_RAW_ADDRESS = '_ct_wallet_withdraw_address';

        public function __construct() {
            add_action('init', [$this, 'register_custom_statuses'], 20);
            add_filter('wc_order_statuses', [$this, 'register_custom_statuses_in_list']);
            add_action('shutdown', [$this, 'capture_frontend_submission'], 20);
            add_action('admin_init', [$this, 'sync_missing_orders'], 20);
            add_action('woocommerce_order_status_changed', [$this, 'handle_status_transition'], 20, 4);
        }

        public function register_custom_statuses() {
            if (!function_exists('wc_get_order_statuses')) {
                return;
            }

            register_post_status(self::STATUS_REQUEST, [
                'label'                     => __('Wallet withdrawal requested', 'cloudtart-support'),
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    'Wallet withdrawal requested <span class="count">(%s)</span>',
                    'Wallet withdrawal requested <span class="count">(%s)</span>',
                    'cloudtart-support'
                ),
            ]);

            register_post_status(self::STATUS_PAID, [
                'label'                     => __('Wallet withdrawal paid out', 'cloudtart-support'),
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    'Wallet withdrawal paid out <span class="count">(%s)</span>',
                    'Wallet withdrawal paid out <span class="count">(%s)</span>',
                    'cloudtart-support'
                ),
            ]);

            register_post_status(self::STATUS_REJECTED, [
                'label'                     => __('Wallet withdrawal rejected', 'cloudtart-support'),
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    'Wallet withdrawal rejected <span class="count">(%s)</span>',
                    'Wallet withdrawal rejected <span class="count">(%s)</span>',
                    'cloudtart-support'
                ),
            ]);
        }

        public function register_custom_statuses_in_list($statuses) {
            $new_statuses = [];

            foreach ($statuses as $key => $label) {
                $new_statuses[$key] = $label;

                if ($key === 'wc-pending') {
                    $new_statuses[self::STATUS_REQUEST] = __('Wallet withdrawal requested', 'cloudtart-support');
                    $new_statuses[self::STATUS_PAID] = __('Wallet withdrawal paid out', 'cloudtart-support');
                    $new_statuses[self::STATUS_REJECTED] = __('Wallet withdrawal rejected', 'cloudtart-support');
                }
            }

            if (!isset($new_statuses[self::STATUS_REQUEST])) {
                $new_statuses[self::STATUS_REQUEST] = __('Wallet withdrawal requested', 'cloudtart-support');
            }

            if (!isset($new_statuses[self::STATUS_PAID])) {
                $new_statuses[self::STATUS_PAID] = __('Wallet withdrawal paid out', 'cloudtart-support');
            }

            if (!isset($new_statuses[self::STATUS_REJECTED])) {
                $new_statuses[self::STATUS_REJECTED] = __('Wallet withdrawal rejected', 'cloudtart-support');
            }

            return $new_statuses;
        }

        public function capture_frontend_submission() {
            if (!$this->is_wallet_submission_request()) {
                return;
            }

            $request = $this->find_latest_submitted_request();
            if (!$request) {
                return;
            }

            $this->ensure_order_for_request($request);
        }

        public function sync_missing_orders() {
            // درخواست‌های AJAX پیشخوان (مثل Heartbeat که هر ۱۵ تا ۶۰ ثانیه تکرار می‌شود) همگام‌سازی
            // را اجرا نمی‌کنند؛ بازکردن صفحات پیشخوان کافی است.
            if (!is_admin() || wp_doing_ajax() || !current_user_can('manage_woocommerce')) {
                return;
            }

            if (get_transient('cloudtart_wallet_withdraw_sync_lock')) {
                return;
            }

            set_transient('cloudtart_wallet_withdraw_sync_lock', '1', 5 * MINUTE_IN_SECONDS);

            global $wpdb;
            $table = $wpdb->prefix . 'fswcwallet_withdrawal_requests';

            $rows = $wpdb->get_results(
                "SELECT request_id, user_id, amount, fee, status, payment_method, address
                 FROM {$table}
                 WHERE status IN ('under_review', 'accepted', 'rejected')
                 ORDER BY request_id DESC
                 LIMIT 30"
            );

            if (empty($rows)) {
                return;
            }

            foreach ($rows as $request) {
                $order_id = $this->find_order_id_by_request_id((int) $request->request_id);

                if (!$order_id && in_array($request->status, ['under_review', 'accepted', 'rejected'], true)) {
                    $order_id = $this->ensure_order_for_request($request);
                }

                if (!$order_id) {
                    continue;
                }

                $order = wc_get_order($order_id);
                if ($order && trim((string) $order->get_customer_note()) === '') {
                    $order->set_customer_note($this->build_customer_withdrawal_details((string) $request->payment_method, (string) $request->address));
                    $order->save();
                }

                if ($request->status === 'accepted') {
                    if ($order && $order->get_status() !== $this->strip_wc_prefix(self::STATUS_PAID)) {
                        $order->update_status(
                            $this->strip_wc_prefix(self::STATUS_PAID),
                            __('The withdrawal request had already been approved on the wallet side; the order has been synced accordingly.', 'cloudtart-support')
                        );
                        $order->update_meta_data(self::META_PROCESSED, 'yes');
                        $order->save();
                    }
                }

                if ($request->status === 'rejected') {
                    if ($order && $order->get_status() !== $this->strip_wc_prefix(self::STATUS_REJECTED)) {
                        $order->update_status(
                            $this->strip_wc_prefix(self::STATUS_REJECTED),
                            __('The withdrawal request had already been rejected on the wallet side; the order has been synced accordingly.', 'cloudtart-support')
                        );
                        $order->update_meta_data(self::META_PROCESSED, 'yes');
                        $order->save();
                    }
                }
            }
        }

        public function handle_status_transition($order_id, $from_status, $to_status, $order) {
            if (!$order instanceof WC_Order) {
                $order = wc_get_order($order_id);
                if (!$order) {
                    return;
                }
            }

            $paid_status = $this->strip_wc_prefix(self::STATUS_PAID);
            $rejected_status = $this->strip_wc_prefix(self::STATUS_REJECTED);

            if ($to_status !== $paid_status && $to_status !== $rejected_status) {
                return;
            }

            $request_id = (int) $order->get_meta(self::META_REQUEST_ID);
            if ($request_id <= 0) {
                return;
            }

            if ($order->get_meta(self::META_PROCESSED) === 'yes') {
                return;
            }

            if ($to_status === $paid_status) {
                $this->settle_wallet_request_from_order($order, $request_id);
                return;
            }

            $this->reject_wallet_request_from_order($order, $request_id);
        }

        private function reject_wallet_request_from_order(WC_Order $order, $request_id) {
            global $wpdb;

            if (!class_exists('Wallet')) {
                $this->log_error('Wallet class not found while rejecting withdrawal request.', [
                    'request_id' => (int) $request_id,
                    'order_id' => (int) $order->get_id(),
                ]);
                return;
            }

            $table = $wpdb->prefix . 'fswcwallet_withdrawal_requests';
            $request = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT request_id, user_id, amount, fee, status FROM {$table} WHERE request_id = %d LIMIT 1",
                    $request_id
                )
            );

            if (!$request) {
                $order->add_order_note(__('The withdrawal request record to reject was not found.', 'cloudtart-support'));
                $this->log_error('Withdrawal request row not found while rejecting order.', [
                    'request_id' => (int) $request_id,
                    'order_id' => (int) $order->get_id(),
                ]);
                return;
            }

            if ($request->status === 'rejected') {
                $order->update_meta_data(self::META_PROCESSED, 'yes');
                $order->save();
                return;
            }

            if ($request->status === 'accepted') {
                $order->add_order_note(__('The withdrawal request has already been paid out and can no longer be rejected.', 'cloudtart-support'));
                return;
            }

            if ($request->status !== 'under_review') {
                $order->add_order_note(
                    sprintf(
                        __('The current withdrawal request status cannot be rejected: %s', 'cloudtart-support'),
                        $request->status
                    )
                );
                return;
            }

            $amount = (float) $request->amount;
            $fee = (float) $request->fee;
            $total_locked = $amount + $fee;

            try {
                Wallet::remove_unavailable_funds((int) $request->user_id, $total_locked);
            } catch (\Throwable $e) {
                $order->add_order_note(__('An error occurred while unlocking the wallet funds. Please check the logs.', 'cloudtart-support'));
                $this->log_error('Exception while unlocking funds for rejected withdrawal.', [
                    'request_id' => (int) $request->request_id,
                    'order_id' => (int) $order->get_id(),
                    'message' => $e->getMessage(),
                ]);
                return;
            }

            $updated = $wpdb->update(
                $table,
                ['status' => 'rejected'],
                ['request_id' => (int) $request->request_id],
                ['%s'],
                ['%d']
            );

            if ($updated === false) {
                $order->add_order_note(__('Failed to update the rejection status in the database. Please review it manually.', 'cloudtart-support'));
                $this->log_error('Failed to update withdrawal request status to rejected.', [
                    'request_id' => (int) $request->request_id,
                    'order_id' => (int) $order->get_id(),
                ]);
                return;
            }

            $order->update_meta_data(self::META_PROCESSED, 'yes');
            $order->add_order_note(
                sprintf(
                    __('Wallet withdrawal request for user #%d was rejected and the locked funds were released.', 'cloudtart-support'),
                    (int) $request->user_id
                )
            );
            $order->save();
        }

        private function settle_wallet_request_from_order(WC_Order $order, $request_id) {
            global $wpdb;

            if (!class_exists('Wallet')) {
                $this->log_error('Wallet class not found while settling withdrawal request.', [
                    'request_id' => (int) $request_id,
                    'order_id' => (int) $order->get_id(),
                ]);
                return;
            }

            $table = $wpdb->prefix . 'fswcwallet_withdrawal_requests';
            $request = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT request_id, user_id, amount, fee, status FROM {$table} WHERE request_id = %d LIMIT 1",
                    $request_id
                )
            );

            if (!$request) {
                $order->add_order_note(__('The withdrawal request record was not found in the database.', 'cloudtart-support'));
                $this->log_error('Withdrawal request row not found while settling order.', [
                    'request_id' => (int) $request_id,
                    'order_id' => (int) $order->get_id(),
                ]);
                return;
            }

            if ($request->status === 'accepted') {
                $order->update_meta_data(self::META_PROCESSED, 'yes');
                $order->save();
                return;
            }

            if ($request->status !== 'under_review') {
                $order->add_order_note(
                    sprintf(
                        __('The current withdrawal request status cannot be settled: %s', 'cloudtart-support'),
                        $request->status
                    )
                );
                return;
            }

            $amount = (float) $request->amount;
            $fee = (float) $request->fee;
            $total_locked = $amount + $fee;

            try {
                Wallet::remove_unavailable_funds((int) $request->user_id, $total_locked);
                Wallet::withdraw_funds(
                    (int) $request->user_id,
                    $amount,
                    (int) $order->get_id(),
                    sprintf(__('Wallet withdrawal payout - Order #%d', 'cloudtart-support'), (int) $order->get_id())
                );

                if ($fee > 0) {
                    Wallet::withdraw_funds(
                        (int) $request->user_id,
                        $fee,
                        (int) $order->get_id(),
                        sprintf(__('Wallet withdrawal fee - Order #%d', 'cloudtart-support'), (int) $order->get_id())
                    );
                }
            } catch (\Throwable $e) {
                $order->add_order_note(__('An error occurred while settling the wallet withdrawal. Please check the logs.', 'cloudtart-support'));
                $this->log_error('Exception while settling wallet withdrawal.', [
                    'request_id' => (int) $request->request_id,
                    'order_id' => (int) $order->get_id(),
                    'message' => $e->getMessage(),
                ]);
                return;
            }

            $updated = $wpdb->update(
                $table,
                ['status' => 'accepted'],
                ['request_id' => (int) $request->request_id],
                ['%s'],
                ['%d']
            );

            if ($updated === false) {
                $order->add_order_note(__('Failed to update the withdrawal request status in the database. Please review it manually.', 'cloudtart-support'));
                $this->log_error('Failed to update withdrawal request status to accepted.', [
                    'request_id' => (int) $request->request_id,
                    'order_id' => (int) $order->get_id(),
                ]);
                return;
            }

            $order->update_meta_data(self::META_PROCESSED, 'yes');
            $order->add_order_note(
                sprintf(
                    __('Wallet withdrawal for user #%1$d settled for the amount of %2$s.', 'cloudtart-support'),
                    (int) $request->user_id,
                    wc_price($amount)
                )
            );
            $order->save();
        }

        private function ensure_order_for_request($request) {
            $request_id = isset($request->request_id) ? (int) $request->request_id : 0;
            if ($request_id <= 0) {
                return 0;
            }

            $existing_order_id = $this->find_order_id_by_request_id($request_id);
            if ($existing_order_id) {
                return $existing_order_id;
            }

            if (!function_exists('wc_create_order')) {
                $this->log_error('wc_create_order is not available while creating withdrawal order.', [
                    'request_id' => (int) $request_id,
                ]);
                return 0;
            }

            $order = wc_create_order([
                'customer_id' => (int) $request->user_id,
                'created_via' => 'cloudtart_wallet_withdraw',
            ]);

            if (!$order || is_wp_error($order)) {
                $this->log_error('Failed to create WooCommerce order for withdrawal request.', [
                    'request_id' => (int) $request_id,
                    'error' => is_wp_error($order) ? $order->get_error_message() : 'unknown',
                ]);
                return 0;
            }

            $billing_data = $this->build_customer_billing_data((int) $request->user_id);
            $order->set_address($billing_data, 'billing');
            $order->set_customer_id((int) $request->user_id);

            $amount = (float) $request->amount;
            $fee = (float) $request->fee;

            $item = new WC_Order_Item_Fee();
            $item->set_name(__('Wallet withdrawal request', 'cloudtart-support'));
            $item->set_total($amount);
            $order->add_item($item);

            $order->set_currency(get_woocommerce_currency());
            $order->update_meta_data(self::META_REQUEST_ID, (int) $request->request_id);
            $order->update_meta_data(self::META_PROCESSED, 'no');
            $order->update_meta_data(self::META_FEE, $fee);
            $order->update_meta_data(self::META_PAYMENT_METHOD, (string) $request->payment_method);
            $order->update_meta_data(self::META_RAW_ADDRESS, (string) $request->address);

            $order->calculate_totals(false);
            $order->save();

            $order->update_status(
                $this->strip_wc_prefix(self::STATUS_REQUEST),
                __('Wallet withdrawal request submitted and awaiting review.', 'cloudtart-support')
            );

            $order->set_customer_note($this->build_customer_withdrawal_details((string) $request->payment_method, (string) $request->address));
            $order->save();

            return (int) $order->get_id();
        }

        private function build_customer_withdrawal_details($method, $address) {
            $decoded_assoc = json_decode($address, true);
            if (is_array($decoded_assoc)) {
                $lines = [];
                $labels = $this->get_form_field_labels_by_method((string) $method);
                foreach ($decoded_assoc as $key => $value) {
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        $label = isset($labels[(string) $key]) ? $labels[(string) $key] : (string) $key;
                        $lines[] = sanitize_text_field((string) $label) . ': ' . sanitize_text_field((string) $value);
                    }
                }

                if (!empty($lines)) {
                    return implode("\n", $lines);
                }
            }

            return sanitize_text_field($address);
        }

        private function get_form_field_labels_by_method($method) {
            $maps = [
                'SWIFT' => [
                    'fn' => __('Full name', 'cloudtart-support'),
                    'bal1' => __('Billing address 1', 'cloudtart-support'),
                    'bal2' => __('Billing address 2', 'cloudtart-support'),
                    'bal3' => __('Billing address 3', 'cloudtart-support'),
                    'city' => __('City', 'cloudtart-support'),
                    'state' => __('State/Province', 'cloudtart-support'),
                    'pc' => __('Postal code', 'cloudtart-support'),
                    'country' => __('Country', 'cloudtart-support'),
                    'bahn' => __('Bank account holder name', 'cloudtart-support'),
                    'iban' => __('Account number / IBAN', 'cloudtart-support'),
                    'swift' => __('SWIFT code', 'cloudtart-support'),
                    'bfn' => __('Bank full name', 'cloudtart-support'),
                    'bbcity' => __('Bank branch city', 'cloudtart-support'),
                    'bbcountry' => __('Bank branch country', 'cloudtart-support'),
                    'ibbc' => __('Intermediary bank code', 'cloudtart-support'),
                    'ibn' => __('Intermediary bank name', 'cloudtart-support'),
                    'ibcity' => __('Intermediary bank city', 'cloudtart-support'),
                    'ibcountry' => __('Intermediary bank country', 'cloudtart-support'),
                ],
                'Bank Transfer' => [
                    'fn' => __('Account holder name', 'cloudtart-support'),
                    'bn' => __('Bank name', 'cloudtart-support'),
                    'ban' => __('Branch/Agency number', 'cloudtart-support'),
                    'bacn' => __('Bank account number', 'cloudtart-support'),
                    'cpf' => __('CPF', 'cloudtart-support'),
                    'type' => __('Account type', 'cloudtart-support'),
                ],
                'Bank Transfer (Turkey)' => [
                    'fn' => __('Account holder name', 'cloudtart-support'),
                    'bn' => __('Bank name', 'cloudtart-support'),
                    'ban' => __('IBAN', 'cloudtart-support'),
                    'bacn' => __('Bank account number', 'cloudtart-support'),
                ],
                'Bank Transfer (Italy)' => [
                    'Nome' => __('First name', 'cloudtart-support'),
                    'Cognome' => __('Last name', 'cloudtart-support'),
                    'Codice fiscale' => __('Tax code', 'cloudtart-support'),
                    'Sesso' => __('Gender', 'cloudtart-support'),
                    'Numero di telefono' => __('Phone number', 'cloudtart-support'),
                    'Via/Piazza e numero' => __('Address (street/square and number)', 'cloudtart-support'),
                    'Codice postale' => __('Postal code', 'cloudtart-support'),
                    'Città' => __('City', 'cloudtart-support'),
                    'Provincia' => __('Province', 'cloudtart-support'),
                    'Paese' => __('Country', 'cloudtart-support'),
                    'Nome di banca' => __('Bank name', 'cloudtart-support'),
                    'IBAN' => __('IBAN', 'cloudtart-support'),
                    'BIC/SWIFT' => __('BIC/SWIFT', 'cloudtart-support'),
                ],
                'Bank Transfer (BACS)' => [
                    'First Name' => __('First name', 'cloudtart-support'),
                    'Last Name' => __('Last name', 'cloudtart-support'),
                    'Bank Name' => __('Bank name', 'cloudtart-support'),
                    'Account Number' => __('Account number', 'cloudtart-support'),
                    'Sort Code' => __('Sort code', 'cloudtart-support'),
                ],
            ];

            return isset($maps[$method]) ? $maps[$method] : [];
        }

        private function build_customer_billing_data($user_id) {
            $user = get_user_by('id', $user_id);
            if (!$user) {
                return [];
            }

            $first_name = (string) get_user_meta($user_id, 'billing_first_name', true);
            $last_name = (string) get_user_meta($user_id, 'billing_last_name', true);

            if ($first_name === '') {
                $first_name = (string) get_user_meta($user_id, 'first_name', true);
            }
            if ($last_name === '') {
                $last_name = (string) get_user_meta($user_id, 'last_name', true);
            }

            return [
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'company'    => (string) get_user_meta($user_id, 'billing_company', true),
                'email'      => (string) $user->user_email,
                'phone'      => (string) get_user_meta($user_id, 'billing_phone', true),
                'address_1'  => (string) get_user_meta($user_id, 'billing_address_1', true),
                'address_2'  => (string) get_user_meta($user_id, 'billing_address_2', true),
                'city'       => (string) get_user_meta($user_id, 'billing_city', true),
                'state'      => (string) get_user_meta($user_id, 'billing_state', true),
                'postcode'   => (string) get_user_meta($user_id, 'billing_postcode', true),
                'country'    => (string) get_user_meta($user_id, 'billing_country', true),
            ];
        }

        private function find_latest_submitted_request() {
            if (!is_user_logged_in()) {
                return null;
            }

            $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0.0;
            $method_data = $this->extract_method_and_address_from_post();
            if (!$method_data) {
                return null;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'fswcwallet_withdrawal_requests';

            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT request_id, user_id, amount, fee, status, payment_method, address
                     FROM {$table}
                     WHERE user_id = %d
                     AND amount = %f
                     AND payment_method = %s
                     AND address = %s
                     AND status = 'under_review'
                     ORDER BY request_id DESC
                     LIMIT 1",
                    get_current_user_id(),
                    $amount,
                    $method_data['method'],
                    $method_data['address']
                )
            );
        }

        private function extract_method_and_address_from_post() {
            if (!isset($_POST['method'])) {
                return null;
            }

            $method_key = sanitize_key($_POST['method']);
            $method = '';
            $address = '';

            if ($method_key === 'paypal') {
                $method = 'PayPal';
                $address = sanitize_text_field(isset($_POST['paypal-address']) ? $_POST['paypal-address'] : '');
            } elseif ($method_key === 'bitcoin') {
                $method = 'Bitcoin';
                $address = sanitize_text_field(isset($_POST['bitcoin-address']) ? $_POST['bitcoin-address'] : '');
            } elseif ($method_key === 'swift') {
                $method = 'SWIFT';
                $address = json_encode(isset($_POST['swift']) ? $_POST['swift'] : []);
            } elseif ($method_key === 'bank') {
                $method = 'Bank Transfer';
                $address = json_encode(isset($_POST['bank']) ? $_POST['bank'] : []);
            } elseif ($method_key === 'bank_turkey') {
                $method = 'Bank Transfer (Turkey)';
                $address = json_encode(isset($_POST['bank_turkey']) ? $_POST['bank_turkey'] : []);
            } elseif ($method_key === 'bank-ita') {
                $method = 'Bank Transfer (Italy)';
                $address = json_encode(isset($_POST['bank-ita']) ? $_POST['bank-ita'] : []);
            } elseif ($method_key === 'bank-bacs') {
                $method = 'Bank Transfer (BACS)';
                $address = json_encode(isset($_POST['bank-bacs']) ? $_POST['bank-bacs'] : []);
            }

            if ($method === '') {
                return null;
            }

            return [
                'method'  => $method,
                'address' => (string) $address,
            ];
        }

        private function is_wallet_submission_request() {
            return isset($_POST['fsww-request-withrawal']) && $_POST['fsww-request-withrawal'] === 'fsww';
        }

        private function find_order_id_by_request_id($request_id) {
            $orders = wc_get_orders([
                'limit'      => 1,
                'type'       => 'shop_order',
                'meta_key'   => self::META_REQUEST_ID,
                'meta_value' => (int) $request_id,
                'return'     => 'ids',
                'orderby'    => 'ID',
                'order'      => 'DESC',
            ]);

            if (empty($orders)) {
                return 0;
            }

            return (int) $orders[0];
        }

        private function strip_wc_prefix($status) {
            return strpos((string) $status, 'wc-') === 0 ? substr($status, 3) : $status;
        }

        private function log_error($message, array $context = []) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error($message, [
                    'source' => 'cloudtart-wallet-withdraw-orders',
                    'context' => $context,
                ]);
                return;
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[cloudtart-wallet-withdraw-orders] ' . $message . ' ' . wp_json_encode($context));
            }
        }
    }
}
