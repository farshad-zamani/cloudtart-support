<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CloudTart_Support_Dokan_Fix')) {
    class CloudTart_Support_Dokan_Fix {

        public function __construct() {
            // Add Dokan filter to disable vendor coupon ensure
            add_filter('dokan_ensure_vendor_coupon', '__return_false', 999);
            
            // Remove Dokan's coupon validation hooks early but preserve WooCommerce native validation
            add_action('plugins_loaded', array($this, 'remove_dokan_coupon_hooks'), 5);
            add_action('init', array($this, 'remove_dokan_coupon_hooks'), 5);
            add_action('wp_loaded', array($this, 'remove_dokan_coupon_hooks'), 5);

            // Ensure WooCommerce native coupon validation is properly restored
            add_action('woocommerce_init', array($this, 'ensure_wc_coupon_validation'), 20);
            add_action('woocommerce_loaded', array($this, 'ensure_wc_coupon_validation'), 20);
            add_action('wp_loaded', array($this, 'ensure_wc_coupon_validation'), 20);

            // Add a direct hook to WooCommerce coupon validation
            add_filter('woocommerce_coupon_is_valid', array($this, 'ensure_coupon_is_valid'), 999, 3);
            add_filter('woocommerce_coupon_validate_minimum_amount', array($this, 'enforce_minimum_amount_validation'), 999, 3);
            add_filter('woocommerce_coupon_validate_maximum_amount', array($this, 'enforce_maximum_amount_validation'), 999, 3);
        }

        /**
         * Check if we are on a relevant page
         */
        private function is_relevant_page() {
            if (wp_doing_ajax()) {
                $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
                $coupon_actions = array(
                    'woocommerce_apply_coupon',
                    'woocommerce_remove_coupon',
                    'woocommerce_update_order_review',
                    'woocommerce_checkout'
                );
                return in_array($action, $coupon_actions);
            }

            if (function_exists('is_cart') && function_exists('is_checkout')) {
                return (is_cart() || is_checkout());
            }

            if (isset($_SERVER['REQUEST_URI'])) {
                $uri = $_SERVER['REQUEST_URI'];
                return (strpos($uri, '/cart') !== false || strpos($uri, '/checkout') !== false);
            }

            return false;
        }

        /**
         * Remove Dokan coupon validation hooks
         */
        public function remove_dokan_coupon_hooks() {
            // Get all callbacks for the filters we want to remove
            global $wp_filter;
            $hooks_to_check = array(
                'woocommerce_coupon_is_valid',
                'woocommerce_coupon_is_valid_for_cart'
            );

            // First approach: Use remove_filter for better compatibility
            foreach ($hooks_to_check as $hook) {
                if (isset($wp_filter[$hook])) {
                    foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                        foreach ($callbacks as $key => $callback) {
                            if (is_array($callback['function']) && is_object($callback['function'][0])) {
                                $class_name = get_class($callback['function'][0]);
                                $method_name = isset($callback['function'][1]) ? $callback['function'][1] : '';

                                // Remove only specific Dokan coupon validation methods
                                if (strpos($class_name, 'Dokan') !== false || strpos($class_name, 'dokan') !== false) {
                                    // Try to use remove_filter first
                                    remove_filter($hook, array($callback['function'][0], $method_name), $priority);
                                    // Also unset directly from the global array as a fallback
                                    unset($wp_filter[$hook]->callbacks[$priority][$key]);
                                }
                            }
                        }
                    }
                }
            }

            // Second approach: Remove all filters at priority 10 as a fallback
            remove_all_filters('woocommerce_coupon_is_valid', 10);
            remove_all_filters('woocommerce_coupon_is_valid_for_cart', 10);

            // Remove Dokan Pro hooks if they exist (with more priorities)
            if (class_exists('Dokan_Pro_Coupons')) {
                foreach ($hooks_to_check as $hook) {
                    // Try multiple common priorities
                    foreach (array(5, 10, 15, 20, 25, 30) as $priority) {
                        remove_all_filters($hook, $priority);
                    }
                }
            }
            
            // Alternative method: Try to access Dokan instance directly
            $this->remove_dokan_instance_hooks();
        }

        /**
         * Try to remove hooks directly from Dokan instance
         */
        private function remove_dokan_instance_hooks() {
            // Try to access Dokan Order Hooks instance
            if (class_exists('WeDevs\\Dokan\\Order\\Hooks')) {
                try {
                    $reflection = new ReflectionClass('WeDevs\\Dokan\\Order\\Hooks');
                    if ($reflection->hasMethod('get_instance')) {
                        $instance = $reflection->getMethod('get_instance')->invoke(null);
                        if ($instance) {
                            remove_filter('woocommerce_coupon_is_valid', array($instance, 'ensure_coupon_is_valid'), 10);
                            remove_filter('woocommerce_coupon_is_valid', array($instance, 'ensure_vendor_coupon'), 10);
                        }
                    }
                } catch (Exception $e) {
                    // Ignore reflection errors
                }
            }
            
            // Try for other Dokan classes
            if (class_exists('Dokan_Pro_Coupons')) {
                try {
                    global $dokan_pro_coupons;
                    if ($dokan_pro_coupons) {
                        remove_filter('woocommerce_coupon_is_valid', array($dokan_pro_coupons, 'ensure_coupon_is_valid'), 10);
                    }
                } catch (Exception $e) {
                    // Ignore errors
                }
            }
        }

        /**
         * Ensure WooCommerce's native coupon validation is working properly
         */
        public function ensure_wc_coupon_validation() {
            // Force WooCommerce to use its native validation
            if (!has_filter('woocommerce_coupon_validate_minimum_amount', array($this, 'enforce_minimum_amount_validation'))) {
                add_filter('woocommerce_coupon_validate_minimum_amount', array($this, 'enforce_minimum_amount_validation'), 999, 3);
            }
            
            if (!has_filter('woocommerce_coupon_validate_maximum_amount', array($this, 'enforce_maximum_amount_validation'))) {
                add_filter('woocommerce_coupon_validate_maximum_amount', array($this, 'enforce_maximum_amount_validation'), 999, 3);
            }

            if (!has_filter('woocommerce_coupon_is_valid', array($this, 'ensure_coupon_is_valid'))) {
                add_filter('woocommerce_coupon_is_valid', array($this, 'ensure_coupon_is_valid'), 999, 3);
            }
        }

        /**
         * Enforce minimum amount validation for coupons
         */
        public function enforce_minimum_amount_validation($is_valid, $coupon, $subtotal = null) {
            if (!$this->is_relevant_page()) {
                return $is_valid;
            }

            if ($subtotal === null && WC()->cart) {
                $subtotal = WC()->cart->get_subtotal();
            }

            $minimum_amount = $coupon->get_minimum_amount();

            if ($minimum_amount > 0 && $subtotal < $minimum_amount) {
                throw new Exception(sprintf(
                    __('The minimum spend for this coupon is %s.', 'woocommerce'),
                    wc_price($minimum_amount)
                ), 108);
            }

            return $is_valid;
        }

        /**
         * Enforce maximum amount validation for coupons
         */
        public function enforce_maximum_amount_validation($is_valid, $coupon, $subtotal = null) {
            if (!$this->is_relevant_page()) {
                return $is_valid;
            }

            if ($subtotal === null && WC()->cart) {
                $subtotal = WC()->cart->get_subtotal();
            }

            $maximum_amount = $coupon->get_maximum_amount();

            if ($maximum_amount > 0 && $subtotal > $maximum_amount) {
                throw new Exception(sprintf(
                    __('The maximum spend for this coupon is %s.', 'woocommerce'),
                    wc_price($maximum_amount)
                ), 112);
            }

            return $is_valid;
        }

        /**
         * Ensure all WooCommerce coupon validation rules are applied
         */
        public function ensure_coupon_is_valid($is_valid, $coupon, $discounts = null) {
            if (!$this->is_relevant_page()) {
                return $is_valid;
            }

            if (!$is_valid) {
                return $is_valid;
            }

            if (!WC()->cart) {
                return $is_valid;
            }

            try {
                $subtotal = WC()->cart->get_subtotal();
                $minimum_amount = $coupon->get_minimum_amount();

                if ($minimum_amount > 0 && $subtotal < $minimum_amount) {
                    throw new Exception(sprintf(
                        __('The minimum spend for this coupon is %s.', 'woocommerce'),
                        wc_price($minimum_amount)
                    ), 108);
                }

                $maximum_amount = $coupon->get_maximum_amount();

                if ($maximum_amount > 0 && $subtotal > $maximum_amount) {
                    throw new Exception(sprintf(
                        __('The maximum spend for this coupon is %s.', 'woocommerce'),
                        wc_price($maximum_amount)
                    ), 112);
                }

                return true;

            } catch (Exception $e) {
                if (method_exists($coupon, 'add_coupon_message')) {
                    $coupon->add_coupon_message($e->getCode() == 108 ? WC_Coupon::E_WC_COUPON_MIN_SPEND_LIMIT_NOT_MET : WC_Coupon::E_WC_COUPON_MAX_SPEND_LIMIT_MET);
                } else {
                    wc_add_notice($e->getMessage(), 'error');
                }
                return false;
            }
        }
    }
}
