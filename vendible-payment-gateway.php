<?php
/**
 * Plugin Name: Vendible Payment Gateway
 * Plugin URI: http://vendible.org
 * Description: Extends WooCommerce by adding custom crypto currencies to your checkout page.
 * Author: http://vendible.org
 * Version: 1.0.0
 * Requires at least: 4.4
 * Tested up to: 5.2.1
 * WC requires at least: 2.6
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce fallback notice.
 *
 * @return string
 * @since 4.1.2
 */
function vendible_pg_missing_wc_alert()
{
    echo '<div class="error"><p><strong>' . sprintf(__('Vendible Gateway requires WooCommerce to be installed and active. You can download %s here.', 'vendible_payment_gateway'), '<a href="' . esc_url('https://woocommerce.com/') . '" target="_blank">WooCommerce</a>') . '</strong></p></div>';
}

/**
 * Check if WooCommerce is active
 */
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    add_action('plugins_loaded', 'vendible_pg_init', 0);

    function vendible_pg_init()
    {
        if (!class_exists('WC_Payment_Gateway'))
            return;

        include_once('include/vendible_payments.php');
    }

    add_filter('woocommerce_payment_gateways', 'vendible_pg_add_to_payment_gateways');

    /**
     * Add the gateway to WC Available Gateways
     *
     * @param array $gateways all available WC gateways
     * @return array $gateways all WC gateways + offline gateway
     * @since 1.0.0
     */
    function vendible_pg_add_to_payment_gateways($gateways)
    {
        $gateways[] = 'vendible_payment_gateway';
        return $gateways;
    }

    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'vendible_pg_plugin_action_links');

    /**
     * Adds plugin page links
     *
     * @param array $links all plugin links
     * @return array $links all plugin links + our custom links (i.e., "Settings")
     * @since 1.0.0
     */
    function vendible_pg_plugin_action_links($links)
    {
        $plugin_links = array(
            '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway')) . '">' . __('Settings', 'vendible_payment_gateway') . '</a>'
        );
        return array_merge($plugin_links, $links);
    }

    if (is_admin()) {
        add_filter('woocommerce_currencies', 'vendible_pg_add_custom_currencies');
        add_filter('woocommerce_currency_symbol', 'vendible_pg_change_existing_currency_symbols', 10, 2);
    }

    /**
     * Add custom gateway currencies to WooCommerce plugin
     *
     * @param array $currencies all available WC currencies
     * @return array $currencies all WC currencies + custom gateway currencies
     * @since 1.0.0
     */
    function vendible_pg_add_custom_currencies($currencies)
    {
        $vendible = new Vendible_Payment_Gateway();
        $res = wp_remote_request(esc_url_raw($vendible->backend_url . '/api/plugin/get-currencies'), array(
            'method' => 'GET',
            'timeout' => 10,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(
                'secret' => $vendible->token,
                'content-type' => 'application/json'),
            'body' => array(),
            'cookies' => array()
        ));
        if (!is_wp_error($res) && isset($res['response']['code']) && $res['response']['code'] === 200) {
            $organizationCurrencies = json_decode($res['body']);
            if (isset($organizationCurrencies->object)) {
                foreach ($organizationCurrencies->object as $organizationCurrency) {
                    $currencies[$organizationCurrency->symbol] = __('Vendible ' . $organizationCurrency->name, 'woocommerce');
                    vendible_pg_change_existing_currency_symbols($organizationCurrency->symbol, $currencies[$organizationCurrency->symbol]);
                }
            }
        } else {
            echo "'<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> custom currencies could not been loaded. Please try again."), 'Vendible Payment Gateway', esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=vendible_payment_gateway'))) . "</p></div>";
        }

        return $currencies;
    }

    /**
     * Add custom gateway currency symbols to WooCommerce plugin
     *
     * @param string $currency_symbol WC currency symbol
     * @param string $currency WC currency
     * @return string $currency_symbol new WC $currency_symbol
     * @since 1.0.0
     */
    function vendible_pg_change_existing_currency_symbols($currency_symbol, $currency)
    {
        if (in_array($currency, ['BTC', 'PIVX', 'LTC', 'IOP', 'BCH'])) {
            $currency_symbol = esc_html($currency);
        }

        return $currency_symbol;
    }

    /**
     * Create DB
     *
     * @param string $currency_symbol WC currency symbol
     * @param string $currency WC currency
     * @return string $currency_symbol new WC $currency_symbol
     * @since 1.0.0
     */
    register_activation_hook(__FILE__, 'vendible_pg_create_database');

    function vendible_pg_create_database()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'vendible_payment_gateway';

        $sql = "CREATE TABLE $table_name (
       `id` INT(32) NOT NULL AUTO_INCREMENT,
	   `oid` INT(32) NOT NULL,
       `pid` VARCHAR(64) NOT NULL,
       UNIQUE KEY id (id)
	) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }


} else {
    add_action('admin_notices', 'vendible_pg_missing_wc_alert');
}
