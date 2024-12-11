<?php
/**
 * Plugin Name: CodesFix JazzCash
 * Plugin URI: https://example.com
 * Description: A Plugin that Integrate Jazzcash into WooCommerce
 * Version: 1.0
 * Author: Your Name
 * Author URI: https://sanwalbajwa.live
 * License: GPL2
 */


if (!defined('ABSPATH')) {
    exit;
}

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

add_action('plugins_loaded', 'init_jazzcash_gateway');


function init_jazzcash_gateway() {
    class WC_JazzCash_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'jazzcash';
            $this->icon = apply_filters('woocommerce_jazzcash_icon', plugins_url('/assets/jazzcash.jpg', __FILE__));
            $this->has_fields = true; // Changed to true to show payment fields
            $this->method_title = 'JazzCash';
            $this->method_description = 'Allows payments through JazzCash';

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            // Define settings
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->password = $this->get_option('password');
            $this->test_mode = $this->get_option('test_mode');

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_jazzcash_gateway', array($this, 'check_response'));
            
            // Add validation filter
            add_filter('woocommerce_payment_complete_order_status', array($this, 'change_payment_complete_order_status'), 10, 3);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable JazzCash Payment',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'Payment method title that customers see at checkout',
                    'default' => 'JazzCash',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'Payment method description that customers see at checkout',
                    'default' => 'Pay securely using JazzCash',
                ),
                'merchant_id' => array(
                    'title' => 'Merchant ID',
                    'type' => 'text',
                    'description' => 'Get your Merchant ID from JazzCash',
                ),
                'password' => array(
                    'title' => 'Password',
                    'type' => 'password',
                    'description' => 'Get your Password from JazzCash',
                ),
                'test_mode' => array(
                    'title' => 'Test mode',
                    'type' => 'checkbox',
                    'label' => 'Enable Test Mode',
                    'default' => 'yes',
                    'description' => 'Place the payment gateway in test mode.',
                )
            );
        }

        // Add payment fields
        public function payment_fields() {
            ?>
            <div class="form-row form-row-wide">
                <p><?php echo $this->description; ?></p>
                
                <label for="jazzcash_mobile_number"><?php _e('Mobile Number', 'wc-jazzcash'); ?> <span class="required">*</span></label>
                <input id="jazzcash_mobile_number" name="jazzcash_mobile_number" type="tel" class="input-text" pattern="03[0-9]{9}" maxlength="11" placeholder="03XXXXXXXXX" required />
                <span class="description"><?php _e('Enter your JazzCash registered mobile number', 'wc-jazzcash'); ?></span>
                
                <label for="jazzcash_cnic"><?php _e('CNIC (Optional)', 'wc-jazzcash'); ?></label>
                <input id="jazzcash_cnic" name="jazzcash_cnic" type="text" class="input-text" pattern="[0-9]{13}" maxlength="13" placeholder="XXXXXXXXXXXXX" />
                <span class="description"><?php _e('Enter your CNIC number without dashes', 'wc-jazzcash'); ?></span>
            </div>
            <?php
        }

        // Validate fields
        public function validate_fields() {
            if (empty($_POST['jazzcash_mobile_number'])) {
                wc_add_notice('Mobile number is required for JazzCash payment.', 'error');
                return false;
            }

            // Validate mobile number format
            if (!preg_match('/^03[0-9]{9}$/', $_POST['jazzcash_mobile_number'])) {
                wc_add_notice('Please enter a valid Pakistani mobile number starting with 03.', 'error');
                return false;
            }

            // Validate CNIC if provided
            if (!empty($_POST['jazzcash_cnic']) && !preg_match('/^[0-9]{13}$/', $_POST['jazzcash_cnic'])) {
                wc_add_notice('Please enter a valid 13-digit CNIC number without dashes.', 'error');
                return false;
            }

            return true;
        }

        public function process_payment($order_id) {
            if ($this->id !== $order->get_payment_method()) {
                return; // Skip if not JazzCash
            }

            global $woocommerce;
            $order = new WC_Order($order_id);

            // Save customer details
            update_post_meta($order_id, '_jazzcash_mobile_number', sanitize_text_field($_POST['jazzcash_mobile_number']));
            if (!empty($_POST['jazzcash_cnic'])) {
                update_post_meta($order_id, '_jazzcash_cnic', sanitize_text_field($_POST['jazzcash_cnic']));
            }

            // JazzCash API parameters
            $api_url = $this->test_mode === 'yes' ? 
                'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform' :
                'https://payments.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform';

            $amount = $order->get_total() * 100; // Amount in cents
            $txn_ref = 'TXN_' . $order_id . '_' . time();

            // Generate hash
            $pp_TxnRefNo = $txn_ref;
            $pp_Amount = $amount;
            $pp_TxnDateTime = date('YmdHis');
            $pp_BillReference = "billRef";
            $pp_Description = "Order Payment";
            $pp_MobileNumber = sanitize_text_field($_POST['jazzcash_mobile_number']);

            $hashString = '';
            $hashString .= $this->merchant_id;
            $hashString .= $pp_TxnRefNo;
            $hashString .= $pp_Amount;
            $hashString .= $pp_TxnDateTime;
            $hashString .= $pp_BillReference;
            $hashString .= $pp_Description;
            $hashString .= $pp_MobileNumber;
            $hashString .= $this->password;

            $pp_SecureHash = hash_hmac('sha256', $hashString, $this->password);

            // Prepare form fields
            $post_args = array(
                'pp_Version' => '1.1',
                'pp_TxnType' => 'MWALLET',
                'pp_Language' => 'EN',
                'pp_MerchantID' => $this->merchant_id,
                'pp_TxnRefNo' => $pp_TxnRefNo,
                'pp_Amount' => $pp_Amount,
                'pp_TxnDateTime' => $pp_TxnDateTime,
                'pp_BillReference' => $pp_BillReference,
                'pp_Description' => $pp_Description,
                'pp_MobileNumber' => $pp_MobileNumber,
                'pp_SecureHash' => $pp_SecureHash,
                'pp_ReturnURL' => WC()->api_request_url('wc_jazzcash_gateway')
            );

            // Store transaction reference in order meta
            update_post_meta($order_id, '_jazzcash_txn_ref', $pp_TxnRefNo);

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => add_query_arg($post_args, $api_url)
            );
        }

        public function check_response() {
            if (isset($_POST['pp_ResponseCode']) && isset($_POST['pp_TxnRefNo'])) {
                $txn_ref = sanitize_text_field($_POST['pp_TxnRefNo']);
                $response_code = sanitize_text_field($_POST['pp_ResponseCode']);

                // Find order by transaction reference
                $args = array(
                    'post_type' => 'shop_order',
                    'meta_key' => '_jazzcash_txn_ref',
                    'meta_value' => $txn_ref,
                );

                $orders = get_posts($args);
                if (!empty($orders)) {
                    $order = wc_get_order($orders[0]->ID);

                    if ($response_code === '000') {
                        // Payment successful
                        $order->payment_complete();
                        $order->add_order_note('JazzCash payment successful. Transaction Reference: ' . $txn_ref);
                    } else {
                        // Payment failed
                        $order->update_status('failed', 'JazzCash payment failed. Response Code: ' . $response_code);
                    }

                    wp_redirect($this->get_return_url($order));
                    exit;
                }
            }

            wp_redirect(wc_get_page_permalink('cart'));
            exit;
        }
    }

    function add_jazzcash_gateway($methods) {
        $methods[] = 'WC_JazzCash_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_jazzcash_gateway');
}

// Add custom CSS for payment fields
add_action('wp_enqueue_scripts', 'enqueue_jazzcash_styles');
function enqueue_jazzcash_styles() {
    if (is_checkout()) { // Load only on the checkout page
        wp_enqueue_style(
            'jazzcash-styles',
            plugins_url('/assets/css/jazzcash-styles.css', __FILE__), // Path to your CSS file
            array(), // Dependencies (if any)
            '1.0', // Version
            'all' // Media type
        );
    }
}
