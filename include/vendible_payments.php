<?php
if (!defined('ABSPATH')) {
    exit;
}

class Vendible_Payment_Gateway extends WC_Payment_Gateway
{

    private $discount;
    protected static $_instance = null;
    private $currencyConversions = [];

    /**
     * Main Vendible_Payment_Gateway Instance
     *
     * Ensures only one instance of Vendible_Payment_Gateway is loaded or can be loaded.
     *
     * @return Vendible_Payment_Gateway - Main instance
     * @since   1.0.0
     * @static
     * @version 1.0.0
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {

        $this->id = 'vendible_payment_gateway';
        $this->icon = apply_filters('woocommerce_offline_icon', '');
        $this->has_fields = false;
        $this->method_title = esc_html__("Vendible Payment Gateway", 'vendible_payment_gateway');
        $this->method_description = esc_html__("Vendible Payment Gateway Plugin for WooCommerce.", 'vendible_payment_gateway');
        $this->vendible_pg_init_form_fields();
        $this->title = $this->get_option('title');
        $this->backend_url = 'http://18.221.81.111:9001';
        //$this->backend_url = 'http://192.168.0.21:9001';
        //$this->backend_url = 'http://apitest.vendible.org';
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions', $this->description);
        $this->version = "1.0.0";
        $this->icon = apply_filters('woocommerce_offline_icon', '');
        $this->has_fields = false;
        $this->log = new WC_Logger();
        $this->address = $this->get_option('address');
        $this->transaction_id = $this->get_option('transaction_id');
        $this->confirms = $this->get_option('confirms');
        $this->discount = $this->get_option('discount');
        $this->token = $this->get_option('token');
        $this->merchantId = $this->get_option('merchantId');
        $this->delete_history = $this->get_option('history');

        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }

        if (is_admin()) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'vendible_pg_activate_plugin_after_key_is_saved'), 10, 2);
            add_action('woocommerce_settings_save_general', array($this, 'vendible_pg_action_woocommerce_settings_save_general'), 10, 2);
        }
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('after_woocommerce_pay', array($this, 'process_payment'));

        // add custom style for popup
        add_action('wp_enqueue_scripts', array($this, 'enqueue_style'));

        // add drop down currency under Vendible payment method
        add_filter('woocommerce_gateway_description', array($this, 'vendible_pg_add_custom_fields_for_currency_selection'), 20, 2);
        add_action('woocommerce_checkout_process', array($this, 'vendible_pg_custom_fields_validation'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'vendible_pg_update_order_metadata_for_custom_fields'));

        // custom thank you page title and text
        add_filter('the_title', array($this, 'vendible_pg_order_received_title'), 10, 2);
        add_filter('woocommerce_thankyou_order_received_text', array($this, 'vendible_pg_order_received_text'), 10, 2);
        add_filter('woocommerce_order_button_html', array($this, 'vendible_pg_custom_order_button_html'), 10, 2);

        // update order status on listing
        add_action('woocommerce_admin_order_actions_end', array($this, 'vendible_pg_update_order_status'), 10, 2);
    }

    /**
     * Update order status
     */
    function vendible_pg_update_order_status($order)
    {
        global $wpdb;
        $result = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . 'vendible_gateway' . " WHERE `oid` = " . $order->get_id());
        if ($result) {
            foreach ($result as $order) {
                $checkResponse = $this->vendible_pg_verify_payment($order->pid);
                switch ($checkResponse->status) {
                    case '':
                        break;
                    case 'null':
                        $order->update_status('wc-failed');
                        break;
                    case 'WAITING_PAYMENT':
                        $order->update_status('wc-pending');
                        break;
                    case 'PAID_NOT_CONFIRMED':
                        $order->update_status('wc-processing');
                        break;
                    case 'CONFIRMED':
                        $order->update_status('wc-completed');
                        break;
                    case 'CANCELED':
                        $order->update_status('wc-cancelled');
                        break;
                    case 'PARTIALLY_PAID':
                        $order->update_status('wc-processing');
                        break;
                    default:
                        break;
                }
            }
        }
    }

    /**
     * Custom title for checkout page
     */
    public function vendible_pg_order_received_title($title, $id)
    {
        if (function_exists('is_order_received_page') &&
            is_order_received_page() && get_the_ID() === $id) {
            $title = esc_html("Order created!");
        }
        return $title;
    }

    /**
     * Custom text for checkout page
     */
    public function vendible_pg_order_received_text($str, $order)
    {
        $new_str = esc_html('Please Complete Payment Below.');
        return $new_str;
    }

    /**
     * Custom HTML for checkout process order button
     */
    public function vendible_pg_custom_order_button_html($order_button)
    {
        // Only when total volume is up to 68
        if (WC()->cart->get_cart_subtotal() <= 1)
            return $order_button;

        $order_button_text = esc_html__("Max volume reached", "woocommerce");

        $style = 'color:#fff;cursor:not-allowed;background-color:#999;';
        return '<a class="button alt" style="' . esc_attr($style) . '" name="woocommerce_checkout_place_order" id="place_order" >' . $order_button_text . '</a>';
    }

    /**
     * Set default currency for Vendible Gateway when saving settings for plugin
     */
    public function vendible_pg_action_woocommerce_settings_save_general($array)
    {
        $this->vendible_pg_ssl_check();
        $savedCurrency = sanitize_text_field($_POST['woocommerce_currency']);
        $res = wp_remote_request(esc_url_raw($this->backend_url . '/api/plugin/default-currency'), array(
            'method' => 'POST',
            'timeout' => 10,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(
                'secret' => $this->token,
                'content-type' => 'application/json'),
            'body' => json_encode(array(
                'object' => $savedCurrency)),
            'cookies' => array()
        ));
        if (!is_wp_error($res) && isset($res['response']['code']) && $res['response']['code'] === 200) {
            echo "<div class=\"notice notice-success is-dismissible\"><p>" . sprintf(__("<strong>%s</strong> default currency successfully saved."), esc_html($this->method_title), esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway'))) . "</p></div>";
        } else {
            echo "<div class=\"notice notice-error is-dismissible\"><p>" . sprintf(__("<strong>%s</strong> default currency could not been saved. Please try again."), esc_html($this->method_title), esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway'))) . "</p></div>";
        }
    }

    /**
     * Add custom style
     */
    public function enqueue_style()
    {
        wp_register_style('vendible_style', plugin_dir_url(__FILE__) . '../assets/css/vendible-style.css', array(), null);
        wp_enqueue_style('vendible_style');
    }

    /**
     * Add custom currency dropdown and amount field on checkout page
     */
    public function vendible_pg_add_custom_fields_for_currency_selection($description, $payment_id)
    {
        global $woocommerce;
        $cart = $woocommerce->cart;
        $paymentCurrency = get_woocommerce_currency();
        $cartTotal = $cart->get_totals()['total'];

        $options = [];
        $currencySymbols = [];
        $currencyMinAmount = [];
        $res = wp_remote_request(esc_url_raw($this->backend_url . '/api/plugin/get-currencies'), array(
            'method' => 'GET',
            'timeout' => 10,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(
                'secret' => $this->token,
                'content-type' => 'application/json'),
            'body' => array(),
            'cookies' => array()
        ));
        if (!is_wp_error($res) && isset($res['response']['code']) && $res['response']['code'] === 200) {
            $organizationCurrencies = json_decode($res['body']);
            if (isset($organizationCurrencies->object)) {
                foreach ($organizationCurrencies->object as $organizationCurrency) {
                    $this->currencyConversions[$organizationCurrency->symbol] = 0;
                    $options[$organizationCurrency->symbol] = esc_html__($organizationCurrency->symbol, "woocommerce");
                    array_push($currencySymbols, $organizationCurrency->symbol);
                    $currencyMinAmount[$organizationCurrency->symbol] = $organizationCurrency->minPricePerOrder;
                }
            }
            // get currency conversion
            if (!isset($this->currencyConversions[$paymentCurrency])) {
                $res = wp_remote_request(esc_url_raw($this->backend_url . '/api/plugin/convert-to-crypto'), array(
                    'method' => 'POST',
                    'timeout' => 10,
                    'redirection' => 5,
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array(
                        'secret' => $this->token,
                        'content-type' => 'application/json'),
                    'body' => json_encode(array(
                        'amount' => $cartTotal,
                        'cryptoSymbols' => $currencySymbols,
                        'fiatSymbol' => $paymentCurrency)),
                    'cookies' => array()
                ));
                if (!is_wp_error($res) && isset($res['response']['code']) && $res['response']['code'] === 200) {
                    $currencyConversions = json_decode($res['body']);
                    if (isset($currencyConversions->object->conversions)) {
                        foreach ($currencyConversions->object->conversions as $currencyConversion) {
                            $this->currencyConversions[$currencyConversion->dst] = $currencyConversion->value;
                            $convRateString = (string)$currencyConversion->convRate;
                            $index = strpos($convRateString, '.') + 1;
                            while ($convRateString[$index] === '0') {
                                $index++;
                            }
                            $convRateString = substr($convRateString, 0, $index + 3);
                            $currencyConversionTextArea = "<div  id='" . esc_html($currencyConversion->dst) . "' style='display: none;'>";
                            $currencyConversionTextArea .= "<p>Amount from " . esc_html($currencyConversion->src) . " to " . esc_html($currencyConversion->dst) . " with conversion rate " . esc_html($convRateString) . " is " . esc_html($currencyConversion->value) . " </p>";
                            $currencyConversionTextArea .= "<p id='" . esc_html($currencyConversion->dst) . "_amount'>" . esc_html($currencyConversion->value) . "</p>";
                            $currencyConversionTextArea .= "<p id='" . esc_html($currencyConversion->dst) . "_min_amount'>" . esc_html($currencyMinAmount[$currencyConversion->dst]) . "</p>";
                            $currencyConversionTextArea .= "</div>";
                            echo $currencyConversionTextArea;
                        }
                    }
                } else {
                    echo "<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> selected currency conversions could not been loaded. Please try again."), esc_html($this->method_title), esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway'))) . "</p></div>";
                }
            } else {
                $options = [];
                $options[$paymentCurrency] = esc_html__($paymentCurrency, "woocommerce");
            }
        }

        if ('vendible_payment_gateway' === $payment_id) {
            ob_start(); // Start buffering

            echo '<div  id="show-transaction-value" style="padding:10px 0px;">';
            echo '</div>';
            echo '<div  class="bacs-fields" style="padding:10px 0px;">';
            echo '<div class="help-tip">';
            echo '<p>Choose the default <b>currency</b>.</p>';
            echo '</div>';

            woocommerce_form_field('custom_currency_vendible', array(
                'type' => 'select',
                'id' => 'custom-currency-select-vendible',
                'label' => __("Choose currency *", "woocommerce"),
                'class' => array('form-row-wide'),
                'required' => true,
                'options' => $options,
            ), '');
            if (isset($organizationCurrencies->object)) {
                foreach ($organizationCurrencies->object as $organizationCurrency) {
                    if ($organizationCurrency->segwitAddress !== NULL) {
                        woocommerce_form_field('custom_address_vendible_' . $organizationCurrency->symbol, array(
                            'type' => 'select',
                            'id' => 'custom-address-select-vendible-' . $organizationCurrency->symbol,
                            'label' => __("Choose address type *", "woocommerce"),
                            'class' => array('form-row-wide'),
                            'required' => true,
                            'options' => [
                                'default' => 'Default',
                                'compatible' => 'Compatible',
                                'segwit' => 'Segwit'
                            ],
                        ), '');
                    }
                }
            }

            woocommerce_form_field('custom_amount_vendible', array(
                'type' => 'text',
                'id' => 'custom-amount-vendible',
                'label' => __("Custom amount *", "woocommerce"),
                'class' => array('form-row-wide'),
                'required' => true,
                'custom_attributes' => array('readonly' => 'readonly')
            ), esc_html($cartTotal));
            echo '<div> <script language="javascript">';
            echo "jQuery(document).ready(function($) { 
                    $('#custom-currency-select-vendible').on('change', function () {
                    var selected_currency = $(this).val();
                    var response_container = $('#show-transaction-value');
                    var amount_container = $('#show-transaction-value');
                    var html_text = $('#' + selected_currency + ' > p').html();
                    var custom_amount_vendible = $('#' + selected_currency + '_amount').html();
                    $('#custom-amount-vendible').val(custom_amount_vendible);
                    var custom_min_amount_vendible = $('#' + selected_currency + '_min_amount').html();
                    if( custom_min_amount_vendible > " . esc_html($cartTotal) . " ) {
                        $('#place_order').text();
                        $('#place_order').prop('disabled', true);
                        $('#place_order').addClass('button-disabled');
                        $('#custom-amount-vendible').val('Order must be higher than ' + custom_min_amount_vendible + ' " . esc_html($paymentCurrency) . "');
                    } else {
                        $('#place_order').prop('disabled', false);
                        $('#place_order').removeClass('button-disabled');
                    }
                    // hide all address options and show the one selected
                    var addressDropdowns = $('select[id^=\"custom-address-select-vendible-\"]').each(function () {
                        $(this).hide();
                        $('label[for=\"' + this.id + '\"]').hide();
                    });
                    $('select[id=\"custom-address-select-vendible-' + selected_currency + '\"]').show();
                    $('label[for=\"custom-address-select-vendible-' + selected_currency + '\"]').show();
                    response_container.html(html_text);                    
            }).trigger('change'); });";
            echo '</script>';

            $description = ob_get_clean() . esc_html($description); // Append buffered content
        }
        return $description;
    }

    /**
     * Process the checkout
     * */
    public function vendible_pg_custom_fields_validation()
    {
        global $woocommerce;
        if (!$_POST['custom_currency_vendible'] || strlen($_POST['custom_currency_vendible']) > 4 || !is_string($_POST['custom_currency_vendible'])) {
            $woocommerce->add_error(__('Payment currency not valid.'));
        }
        if (!$_POST['custom_amount_vendible'] || !is_numeric($_POST['custom_amount_vendible'])) {
            $woocommerce->add_error(__('Payment custom amount not valid.'));
        }
    }

    /**
     * Update the order metadata with custom field values
     * */
    public function vendible_pg_update_order_metadata_for_custom_fields($order_id)
    {
        $order = wc_get_order($order_id);

        if (isset($_POST['custom_currency_vendible'])) {
            $customCurrencyVendible = sanitize_text_field($_POST['custom_currency_vendible']);
            $customAmountVendible = sanitize_text_field($_POST['custom_amount_vendible']);
            if ($_POST['custom_address_vendible_' . $customCurrencyVendible]) {
                $customAddressVendible = sanitize_text_field($_POST['custom_address_vendible_' . $customCurrencyVendible]);
                update_post_meta($order_id, 'custom_address_vendible_' . $customCurrencyVendible, $customAddressVendible);
            }
            if (strlen($customCurrencyVendible) === 3 || strlen($customCurrencyVendible) === 4 && preg_match('/[A-Z]/', $customCurrencyVendible)) {
                update_post_meta($order_id, 'custom_currency_vendible', $customCurrencyVendible);
            }
            if (is_numeric($customAmountVendible)) {
                update_post_meta($order_id, 'custom_amount_vendible', $customAmountVendible);
            }
        }
    }

    /**
     * Activate plugin, check URL provided and set default currency of the Vendible Gateway
     */
    public function vendible_pg_activate_plugin_after_key_is_saved()
    {
        $this->vendible_pg_ssl_check();
        $post_data = $this->get_post_data();
        $res = wp_remote_request(esc_url_raw($this->backend_url . '/api/plugin/activate-plugin'), array(
            'method' => 'POST',
            'timeout' => 10,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(
                'secret' => $this->get_field_value('token', $post_data),
                'content-type' => 'application/json'),
            'body' => array(),
            'cookies' => array()
        ));
        if (is_wp_error($res) || !isset($res['response']['code']) || $res['response']['code'] !== 200) {
            echo "<div class=\"notice notice-error is-dismissible\"><p>" . sprintf(__("<strong>%s</strong> was not activated. Please try again."), esc_html($this->method_title), esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway'))) . "</p></div>";
        } else {
            // check if organization URL is valid
            $json_res = json_decode($res['body']);
            if ($json_res->object) {
                $resCheckPath = wp_remote_request(esc_url_raw($this->backend_url . '/api/plugin/test-path'), array(
                    'method' => 'GET',
                    'timeout' => 10,
                    'redirection' => 5,
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array(
                        'secret' => $this->get_field_value('token', $post_data),
                        'content-type' => 'application/json'),
                    'body' => array(),
                    'cookies' => array()
                ));

                if (is_wp_error($resCheckPath) || !isset($resCheckPath['response']['code']) || $resCheckPath['response']['code'] !== 200) {
                    echo "<div class=\"notice notice-error is-dismissible\"><p>" . sprintf(__("Could not test your organization URL.")), esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_gateway')) . "</p></div>";
                } else {
                    $json_res_check_path = json_decode($resCheckPath['body']);
                    if ($json_res_check_path->object) {
                        $shopCurrency = get_woocommerce_currency();
                        // save shop currency
                        $res = wp_remote_request(esc_url_raw($this->backend_url . '/api/plugin/default-currency'), array(
                            'method' => 'POST',
                            'timeout' => 10,
                            'redirection' => 5,
                            'httpversion' => '1.0',
                            'blocking' => true,
                            'headers' => array(
                                'secret' => $this->get_field_value('token', $post_data),
                                'content-type' => 'application/json'),
                            'body' => json_encode(array(
                                'object' => $shopCurrency)),
                            'cookies' => array()
                        ));
                        if (!is_wp_error($res) && isset($res['response']['code']) && $res['response']['code'] === 200) {
                            echo "<div class=\"notice notice-success is-dismissible\"><p>" . sprintf(__("<strong>%s</strong> default currency successfully saved."), esc_html($this->method_title), esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway'))) . "</p></div>";
                        } else {
                            echo "<div class=\"notice notice-error is-dismissible\"><p>" . sprintf(__("<strong>%s</strong> default currency could not been saved. Please try again."), esc_html($this->method_title), esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway'))) . "</p></div>";
                        }
                    } else {
                        echo "<div class=\"notice notice-error is-dismissible\"><p>" . sprintf(__("Your organization URL doesn't match with your shop URL (<strong>%s</strong>)"), esc_html($json_res_check_path->object), esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway'))) . "</p></div>";
                    }
                }
            } else {
                echo "<div class=\"notice notice-error is-dismissible\"><p>" . sprintf(__("Your API key is invalid. Please try again."), esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway'))) . "</p></div>";
            }
        }
        return;
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function vendible_pg_init_form_fields()
    {

        $this->form_fields = array(
            'enabled' => array(
                'title' => esc_html__('Enable / Disable', 'vendible_payment_gateway'),
                'label' => esc_html__('Enable this Vendible payment gateway.', 'vendible_payment_gateway'),
                'type' => 'checkbox',
                'default' => 'no'
            ),
            'title' => array(
                'title' => esc_html__('Title', 'vendible_payment_gateway'),
                'type' => 'text',
                'description' => esc_html__('Payment title the customer will see during the checkout process.', 'vendible_payment_gateway'),
                'default' => esc_html__('Vendible', 'vendible_payment_gateway')
            ),
            'description' => array(
                'title' => esc_html__('Description', 'vendible_payment_gateway'),
                'type' => 'textarea',
                'description' => esc_html__('Payment description the customer will see during the checkout process.', 'vendible_payment_gateway'),
                'default' => esc_html__('Pay securely using Vendible.', 'vendible_payment_gateway')
            ),
            'token' => array(
                'title' => esc_html__('API Key', 'vendible_payment_gateway'),
                'description' => esc_html__('Get your API key by logging into vendible.org/admin', 'vendible_payment_gateway'),
                'type' => esc_html__('text'),
                'default' => ''
            ),
            'merchantId' => array(
                'title' => esc_html__('Merchant ID', 'vendible_payment_gateway'),
                'description' => esc_html__('Get your merchant ID by logging into vendible.org/admin', 'vendible_payment_gateway'),
                'type' => esc_html__('text'),
                'default' => ''
            ),
            'discount' => array(
                'title' => esc_html__('% discount for using Vendbile', 'vendible_payment_gateway'),
                'description' => esc_html__('Provide a discount to your customers who pay with Vendible! Leave this empty if you do not wish to provide a discount.', 'vendible_payment_gateway'),
                'type' => esc_html__('text'),
                'default' => '5'
            ),
        );
    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page($order_id)
    {
        global $wpdb;
        if (get_post_meta($order_id, 'delivery_order_id', true))
            return; // Exit if already processed
        $order = wc_get_order($order_id);
        $amount = floatval(preg_replace('#[^\d.]#', '', $order->get_total()));
        $customAmount = esc_html(get_post_meta($order_id, 'custom_amount_vendible', true));
        $currency = $order->get_currency();
        $customCurrency = esc_html(get_post_meta($order_id, 'custom_currency_vendible', true));
        $customAddress = esc_html(get_post_meta($order_id, 'custom_address_vendible_' . $customCurrency, true));
        $address = get_option($order_id . "address");
        $icon = '';
        $message = '';
        if (!$address) {
            $currentUser = wp_get_current_user();
            $woocommerceUserId = (isset($currentUser->ID) ? (int)$currentUser->ID : 0);
            $res = wp_remote_request(esc_url_raw($this->backend_url . '/api/w/transaction/'), array(
                'method' => 'PUT',
                'timeout' => 10,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => array(
                    'secret' => $this->token,
                    'content-type' => 'application/json'),
                'body' => json_encode(array(
                    'woocommerceId' => $order_id,
                    'price' => ($customAmount) ? $customAmount : $amount,
                    'currency' => ($customCurrency) ? $customCurrency : $currency,
                    'woocommerceUserId' => $woocommerceUserId,
                    'email' => sanitize_text_field($order->get_billing_email()),
                    'firstName' => sanitize_text_field($order->get_billing_first_name()),
                    'lastName' => sanitize_text_field($order->get_billing_last_name()))),
                'cookies' => array()
            ));
            if (!is_wp_error($res) && isset($res['response']['code']) && $res['response']['code'] === 200) {
                $json_res = json_decode($res['body']);
                if ($customAddress) {
                    if ($customAddress === 'default') {
                        $this->address = esc_html($json_res->object->address);
                    } else if ($customAddress === 'compatible') {
                        $this->address = esc_html($json_res->object->newCompAddress);
                    } else if ($customAddress === 'segwit') {
                        $this->address = esc_html($json_res->object->newSegwitAddress);
                    }
                } else {
                    $this->address = esc_html($json_res->object->address);
                }
                $this->transaction_id = esc_html($json_res->object->id);
                update_option($order_id . "address", $this->address);
                $checkResponse = $this->vendible_pg_verify_payment($json_res->object->id);
                $table_name = $wpdb->prefix . 'vendible_gateway';
                $wpdb->insert(
                    $table_name,
                    array(
                        'oid' => $order_id,
                        'pid' => esc_html($json_res->object->id)
                    )
                );
                switch ($checkResponse->status) {
                    case '':
                        $message = 'Could not verify you payment status.';
                        break;
                    case 'null':
                        $message = 'Payment failed.';
                        $order->update_status('wc-failed');
                        break;
                    case 'WAITING_PAYMENT':
                        $message = 'Awaiting direct payment.';
                        $order->update_status('wc-pending');
                        break;
                    case 'PAID_NOT_CONFIRMED':
                        $message = 'Paid and waiting for confirmation.';
                        $order->update_status('wc-processing');
                        break;
                    case 'CONFIRMED':
                        $message = 'Confirmed payment.';
                        $order->update_status('wc-completed');
                        break;
                    case 'CANCELED':
                        $message = 'Cancelled payment.';
                        $order->update_status('wc-cancelled');
                        break;
                    case 'PARTIALLY_PAID':
                        $message = 'Partially paid (' . esc_html($checkResponse->partialAmount / pow(10, $checkResponse->precision)) . ')';
                        $order->update_status('wc-processing');
                        break;
                    default:
                        $message = 'Unknown.';
                        break;
                }
                switch ($customCurrency) {
                    case 'BTC':
                        $icon = 'bitcoin.png';
                        break;
                    case 'BCH':
                        $icon = 'bitcoin-cash.png';
                        break;
                    case 'LTC':
                        $icon = 'litecoin.png';
                        break;
                    case 'IOP':
                        $icon = 'iop.png';
                        break;
                    case 'PIVX':
                        $icon = 'pivx.png';
                        break;
                }
            } else {
                $message = 'Your payment has not been sent. Please try again later';
            }
            if ($this->discount) {
                $sanatized_discount = preg_replace('/[^0-9]/', '', $this->discount);
                $price = ($customAmount) ? $customAmount : $amount . " " . ($customCurrency) ? $customCurrency : $currency . " ( " . $sanatized_discount . "% discount for using " . ($customCurrency) ? $customCurrency : $currency . " !)";
            } else {
                $price = ($customAmount) ? $customAmount : $amount . " " . ($customCurrency) ? $customCurrency : $currency;
            }
            $text = "<div class='page-container'>
                    <div class='container-TRTL-payment'>
                        <div class='content-TRTL-payment'>
                            <div class='TRTL-amount-send'>
                                <span class='TRTL-label'>Amount:</span>
                                <span class='TRTL-label-amount'>
                                    <p>" . esc_html($customAmount . " " . $customCurrency) . " </p>
                                    <img style='width: 25px; height: 25px; margin-left: 0.3em;' src='" . esc_url(plugin_dir_url(dirname(__FILE__)) . "assets/" . $icon) . "'></span>
                            </div>
                            <br>
                            <div class='TRTL-address'>
                                <span class='TRTL-label' style='font-weight:bold;'>Address:</span>
                                <div class='TRTL-address-box'>
                                    <p id='TRTL-address-box'>" . esc_html($this->address) . "</p>
                                    <p id='TRTL-amount-box'>" . esc_html($customAmount) . "</p>
                                </div>                              
                                <div>
                                    <button onclick='copyToClipboard(1)'>Copy address</button>
                                    <button onclick='copyToClipboard(2)'>Copy amount</button>
                                </div>
                            </div>
                            <br>
                            
                            </div>
                            <br>
                            <div class='TRTL-qr-section'>
                                <div class='TRTL-verification-message'>
                                    <h4 style='color: red;'>" . esc_html($message) . "</h4>                    
                                </div>
                                <div class='TRTL-qr-code'>
                                    <div class='TRTL-qr-code-box'><img src='" . esc_url('https://api.qrserver.com/v1/create-qr-code/? size=200x200&data=' . $this->address) . "' /></div>
                                    
                                </div>
                                <p class='about-link'>
                                    <a href='https://vendible.com' target='_blank'>About vendible</a>
                                </p>                                
                            </div>
                        </div>
                        <div class='footer-TRTL-payment' style='text-align:center;margin: 15px 0 15px 0px;'>
                            <small>Transaction should take no longer than a few minutes. This page refreshes automatically every 30 seconds.</small>
                        </div>
                    </div>
                </div>
                
        ";
            $text .= "<script language='javascript'>
            jQuery(document).ready(function($) {
                setInterval(function() {
                    var transaction_id = '" . esc_html($this->transaction_id) . "';
                    if (transaction_id !== undefined && transaction_id !== null) {
                        $.ajax({
                            url: '" . esc_url_raw($this->backend_url) . "/api/w/transaction/status?id=' + transaction_id,
                            type: 'get',
                            headers: {'content-type': 'application/json', 'secret': '" . esc_html($this->token) . "'},
                            success: function(data) {
                                var checkStatus = jQuery.parseJSON(JSON.stringify(data));
                                var message = '';
                                switch (checkStatus.status) {
                                    case '':
                                        message = 'Could not verify you payment status';
                                        break;
                                    case 'WAITING_PAYMENT':
                                        message = 'Awaiting direct payment.';
                                        break;
                                    case 'PAID_NOT_CONFIRMED':
                                        message = 'Paid and waiting for confirmation';
                                        break;
                                    case 'CONFIRMED':
                                        message = 'Confirmed payment.';
                                        break;
                                    case 'CANCELED':
                                        message = 'Cancelled payment.';
                                        break;
                                    case 'PARTIALLY_PAID':
                                        const string = (checkStatus.partialAmount / Math.pow(10, checkStatus.precision)).toFixed(checkStatus.precision);
                                        let index = string.length - 1;
                                        while (string[index] === '0') {
                                          index--;
                                        }
                                        const partialPaidamount = Number.parseFloat((checkStatus.partialAmount / Math.pow(10, checkStatus.precision))).toFixed(index - 1).toString();
                                        message = 'Partially paid (' + partialPaidamount + ' " . esc_html($customCurrency) . ")';
                                        break;
                                    default:
                                        message = 'Unknown.';
                                    break;
                                }
                                $('.TRTL-verification-message > h4').html(message); //insert text of test.php into your div
                            },
                            fail: function() {
                                $('.TRTL-verification-message > h4').html('Could not check payment status');
                            }
                        });
                    }
                }, 10000);
            });
            var jExample = jQuery.noConflict();
            function copyToClipboard(target) {
                var temp = jExample('<input>');
                jExample('body').append(temp);
                if(target === 1) {
                    temp.val(jExample('#TRTL-address-box').text()).select();
                } else {
                    temp.val(jExample('#TRTL-amount-box').text()).select();
                }                
                document.execCommand('copy');
                temp.remove();
            }
            </script>";
            echo wpautop(wptexturize($text));
        }
    }

    /**
     * Process the payment and return the result
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $order->update_status('wc-pending', __('Awaiting direct payment', 'vendible_payment_gateway'));


        $order->reduce_order_stock();

        WC()->cart->empty_cart();

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    /**
     * Check payment status on currency network
     */
    public function vendible_pg_verify_payment($address)
    {
        $res = wp_remote_get(esc_url_raw($this->backend_url . '/api/w/transaction/status?id=' . $address), array(
            'timeout' => 10,
            'redirection' => 5,
            'httpversion' => '1.0',
            'headers' => array(
                'secret' => $this->token,
                'content-type' => 'application/json'
            )
        ));
        if (!is_wp_error($res) && isset($res['response']['code']) && $res['response']['code'] === 200 && isset($res['body'])) {
            $json_res = json_decode($res['body']);
            return $json_res;
        }
        return '';
    }

    /**
     * Check if info is served over SSL
     */
    public function vendible_pg_ssl_check()
    {
        if ($this->enabled == "yes" && !$this->get_option('onion_service')) {
            if (get_option('woocommerce_force_ssl_checkout') == "no") {
                echo "<div class=\"notice notice-error is-dismissible\"><p>" . sprintf(__("<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>"), esc_html($this->method_title), esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway'))) . "</p></div>";
            }
        }
    }

}
