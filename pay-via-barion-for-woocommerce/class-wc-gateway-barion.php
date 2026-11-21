<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Barion\BarionClient;
use Barion\Enumerations\BarionEnvironment;

// Barion PHP SDK v2.1.0 is PSR-4 namespaced (Barion\...). Requiring BarionClient
// pulls in the whole SDK: the file self-loads the bundled autoloader (relative to
// its own directory), which require_once's every model/enum/helper class.
require_once 'barion-library/library/BarionClient.php';
require_once 'includes/class-wc-gateway-barion-ipn-handler.php';
require_once 'includes/class-wc-gateway-barion-return-from-payment.php';
require_once 'includes/class-wc-gateway-barion-request.php';

class WC_Gateway_Barion extends WC_Payment_Gateway {

    /**
     * @var WC_Gateway_Barion_Profile_Monitor
     */
    private $profile_monitor;
    /**
     * @var string
     */
    private $barion_pixel_id;
    /**
     * @var BarionClient
     */
    private $barion_client;
    /**
     * @var string
     */
    private $poskey;
	 /**
     * @var string
     */
   	public $payee;
    /**
     * @var string
     */
    private $barion_environment;
    /**
     * @var string[]
     */
    private $supported_currencies;

    public function __construct($profile_monitor) {
        $this->profile_monitor = $profile_monitor;

        $this->id                 = 'barion';
        $this->method_title       = __('Barion', 'pay-via-barion-for-woocommerce');
        $this->method_description = sprintf( __( 'Barion payment gateway sends customers to Barion to enter their payment information. Barion callback requires cURL support to update order statuses after payment. Check the %ssystem status%s page for more details.', 'pay-via-barion-for-woocommerce' ), '<a href="' . admin_url( 'admin.php?page=wc-status' ) . '">', '</a>' );
        $this->has_fields         = false;
        $this->order_button_text  = __( 'Proceed to Barion', 'pay-via-barion-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds'
        );
        $this->supported_currencies = array('USD', 'EUR', 'HUF', 'CZK', 'PLN');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->settings['title'];
		$allowedTags = '<p><br><strong><b><i><em><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre>';
        $this->description =  strip_tags($this->settings['description'], $allowedTags);
        $this->barion_environment = BarionEnvironment::Prod;

        if ( array_key_exists('environment', $this->settings) && $this->settings['environment'] == 'test' ) {
            $this->title .= __(' [TEST MODE]', 'pay-via-barion-for-woocommerce');
		$this->description .=sprintf( __(  '<br/><br/>Test mode is <strong>active</strong>.  Test credit card details: %s', 'pay-via-barion-for-woocommerce'), '<a href="https://docs.barion.com/Sandbox#Test_cards">https://docs.barion.com/Sandbox#Test_cards</a>');

            $this->barion_environment = BarionEnvironment::Test;
        }

        $this->poskey = $this->settings['poskey'];
        $this->payee = $this->settings['payee'];
        $this->barion_pixel_id = array_key_exists('barion_pixel_id', $this->settings) ? $this->settings['barion_pixel_id'] : '';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        if (!$this->is_selected_currency_supported()) {
            $this->enabled = 'no';
        } else {
            $this->barion_client = new BarionClient($this->poskey, 2, $this->barion_environment);
            $callback_handler = new WC_Gateway_Barion_IPN_Handler($this->barion_client, $this);
            $order_received_handler = new WC_Gateway_Barion_Return_From_Payment($this->barion_client, $this);
            do_action('woocommerce_barion_init', $this->barion_client, $this);
        }
    }

    public function plugin_url() {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    /** @var boolean */
    static $debug_mode = false;

    /** @var WC_Logger Logger instance */
    static $log = null;

    public static function log($message, $level = 'error') {
        if ($level != 'error' && !self::$debug_mode) {
            return;
        }

        if (empty(self::$log)) {
            self::$log = new WC_Logger();
        }

        self::$log->add('barion', $message);
    }

    /**
     * Records block checkout ("Store API") order placements that fail before
     * this gateway is reached.
     *
     * Without this, such a failure leaves NOTHING in the Barion log -- the
     * gateway never runs -- while WooCommerce only writes an unexplained
     * "[Store API #FAIL] Placing Order failed" line into its own place-order
     * log, keeping the actual reason in the log context. That combination makes
     * the failure impossible to diagnose on a live shop, which is exactly what
     * happened to a merchant on 2026-08-17.
     *
     * @param WP_REST_Response|WP_Error|mixed $response
     * @param array                           $handler
     * @param WP_REST_Request                 $request
     * @return WP_REST_Response|WP_Error|mixed
     */
    public static function log_store_api_checkout_failure($response, $handler, $request) {
        if (!is_a($request, 'WP_REST_Request')
            || 'POST' !== $request->get_method()
            || '/wc/store/v1/checkout' !== untrailingslashit($request->get_route())
            || 'barion' !== $request->get_param('payment_method')) {
            return $response;
        }

        if (is_wp_error($response)) {
            self::log(sprintf(
                'Block checkout could not place the order. WooCommerce error code: %s. Message: %s',
                $response->get_error_code(),
                $response->get_error_message()
            ));

            return $response;
        }

        if (!is_a($response, 'WP_REST_Response') || $response->get_status() < 400) {
            return $response;
        }

        $data = $response->get_data();

        self::log(sprintf(
            'Block checkout could not place the order (HTTP %d). WooCommerce error code: %s. Message: %s',
            $response->get_status(),
            isset($data['code']) ? $data['code'] : '(none)',
            isset($data['message']) ? $data['message'] : '(none)'
        ));

        return $response;
    }

    /**
    * Get gateway icon.
    * @return string
     */
    public function get_icon() {
        $icon      = $this->plugin_url() . '/assets/barion-logo.png';
        $info_link = $this->get_icon_info_link();
        $icon_html = '<a href="' . esc_attr( $info_link ) . '" target="_blank"><img src="' . esc_attr( $icon ) . '" alt="' . esc_attr__( 'Barion acceptance mark', 'pay-via-barion-for-woocommerce' ) . '" style="display: inline" /></a>';
        return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
    }

    function get_icon_info_link() {
        if(get_locale() == "hu_HU") {
            return 'https://www.barion.com/hu/';
        }

        return 'https://www.barion.com/en/';
    }

public function get_order_button_text() {
	return $this->order_button_text;
}
public function get_successfull_status() {
	return $this->settings["order_status"];
}


public function get_rejected_status() {
	return $this->settings["rejected_status"];
}
public function get_expired_status() {
	return $this->settings["expired_status"];
}

    function init_form_fields() {
        $this->form_fields = include('includes/settings-barion.php');
    }

    public function admin_options() {
        if ($this->is_selected_currency_supported()) {
            parent::admin_options();
        } else {
            ?>
            <div class="inline error">
                <p>
                    <strong><?php _e('Gateway Disabled', 'woocommerce'); ?></strong>:
                    <?php echo sprintf(__('Barion does not support your store currency. Supported currencies: %s', 'pay-via-barion-for-woocommerce'), implode(', ', $this->supported_currencies)); ?>
                </p>
            </div>
            <?php
        }
    }

    public function is_selected_currency_supported() {
        return in_array(get_woocommerce_currency(), apply_filters('woocommerce_barion_supported_currencies', $this->supported_currencies));
    }

    /**
     * Processes and saves options, send newsletter signup if user agreed.
     */
    public function process_admin_options() {
        parent::process_admin_options();

        try {
            $data = array();
            if($this->settings['tracking_enabled'] === 'yes') {
                $data['url'] = home_url();

                $current_user = wp_get_current_user();
                $data['email'] = $current_user->user_email;
                $data['first_name'] = $current_user->user_firstname;
                $data['last_name'] = $current_user->user_lastname;
                $data['locale'] = get_locale();
                $data['ip'] = $this->get_ip();

                $data['admin_email'] = get_option('admin_email');
                $data['event_name'] = 'Settings saved';
            }
            else {
                $data['event_name'] = 'Settings saved - no newsletter signup';
            }

            wp_remote_get('https://tracking.szelpe.hu/?data=' . base64_encode(json_encode((object)$data)));
        }
        catch(Error $e) {}
        catch(Exception $e) {}
    }

    function get_ip()
    {
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key)
        {
            if (array_key_exists($key, $_SERVER) === true)
            {
                foreach (array_map('trim', explode(',', $_SERVER[$key])) as $ip)
                {
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false)
                    {
                        return $ip;
                    }
                }
            }
        }
    }

    function process_payment($order_id) {
        $order = new WC_Order($order_id);

        do_action('woocommerce_barion_process_payment', $order);

        if($order->get_total() <= 0) {
            $this->payment_complete($order);

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        $request = new WC_Gateway_Barion_Request($this->barion_client, $this, $this->profile_monitor);

        $request->prepare_payment($order);

        if(!$request->is_prepared) {
            return array(
                'result' => 'failure'
            );
        }

        $redirectUrl = $request->get_redirect_url();

        $order->add_order_note(__('User redirected to the Barion payment page.', 'pay-via-barion-for-woocommerce') . ' redirectUrl: "' . $redirectUrl . '"');

        return array(
            'result' => 'success',
            'redirect' => $redirectUrl
        );
    }

    /**
     * Process a refund if supported.
     * @param  int    $order_id
     * @param  float  $amount
     * @param  string $reason
     * @return bool True or false based on success, or a WP_Error object
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = new WC_Order( $order_id );

        if(!$this->can_refund_order($order)) {
            $this->log('Refund Failed: No transaction ID');
            return new WP_Error('error', __('Refund Failed: No transaction ID', 'pay-via-barion-for-woocommerce'));
        }

        include_once('includes/class-wc-gateway-barion-refund.php');
        $barionRefund = new WC_Gateway_Barion_Refund($this->barion_client, $this);
        $result = $barionRefund->refund_order($order, $amount, $reason);

        if($barionRefund->refund_succeeded) {
            $order->add_order_note(sprintf(__('Refunded %s - Refund ID: %s', 'pay-via-barion-for-woocommerce' ), wc_price($barionRefund->refund_amount), $barionRefund->refund_transaction_id));

            return true;
        }

        $wp_error = new WP_Error('barion_refund', __('Barion refund failed.', 'pay-via-barion-for-woocommerce'));

        if(!empty($result->Errors)) {
            foreach($result->Errors as $error) {
                $wp_error->add($error->ErrorCode, $error->Title . ' ' . $error->Description);
            }
        }

        return $wp_error;
    }

    public function can_refund_order($order) {
        return $order && $order->get_transaction_id() && $this->get_barion_payment_id($order);
    }

    const BARION_PAYMENT_ID_META_KEY = 'Barion paymentId';
    public function get_barion_payment_id($order) {
        $paymentMeta = $order->get_meta(self::BARION_PAYMENT_ID_META_KEY);

        if(empty($paymentMeta)) {
            return null;
        }

        return $paymentMeta;
    }

    public function set_barion_payment_id($order, $paymentId) {
        $order->update_meta_data(self::BARION_PAYMENT_ID_META_KEY, $paymentId);
        $order->save();
    }

    public function payment_complete($order, $transaction_id = '') {
        $order->payment_complete($transaction_id);
        $this->update_order_status($order);
    }

    /**
     * Serialises the "already paid? -> finalise" step of the return page and
     * the IPN for one order.
     *
     * Barion fires the IPN and redirects the browser at the same moment. Both
     * requests spend about a second in GetPaymentState() and then checked the
     * order they had loaded BEFORE that call, so neither saw the other's
     * payment and payment_complete() ran twice: on the test shop on 2026-11-20
     * in 14 of 18 simultaneous pairs, each time with two "Completed" and two
     * "New order" mails, and in 8 of them with two download permission rows.
     *
     * MySQL GET_LOCK() is atomic, and the server drops the lock when the
     * request's connection closes, so a request that dies inside cannot leave
     * it stuck. An option or a transient would not do: reading and writing it
     * are two separate queries, which is the very race this closes.
     *
     * @param int $order_id
     * @return bool False only if another request held the lock for the whole
     *              wait: that request is finalising this order. If the database
     *              cannot give a lock at all (NULL), the step runs unlocked, as
     *              it did before, rather than never finalising the order.
     */
    public function lock_order($order_id) {
        global $wpdb;

        // 10 s is several times what finalising takes (about 2 s with the mails
        // on the test shop), so the waiting request normally gets the lock and
        // then finds the order already paid.
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::order_lock_name($order_id), 10));

        if (null === $result) {
            self::log('Could not take the lock for order #' . $order_id . ', continuing without it. ' . $wpdb->last_error);

            return true;
        }

        return '1' === (string) $result;
    }

    /**
     * @param int $order_id
     */
    public function unlock_order($order_id) {
        global $wpdb;

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::order_lock_name($order_id)));
    }

    /**
     * Lock names are global to the MySQL server, hence the table prefix. Two
     * sites sharing both a server and a prefix would only wait for each other
     * on equal order IDs, which is harmless.
     */
    private static function order_lock_name($order_id) {
        global $wpdb;

        return $wpdb->prefix . 'barion_order_' . (int) $order_id;
    }

    /**
     * Loads the order again from the database, past this request's caches:
     * whatever this request loaded earlier may predate the other request's
     * payment.
     *
     * @param WC_Order $order
     * @return WC_Order
     */
    public function get_fresh_order($order) {
        clean_post_cache($order->get_id());
        $class = get_class($order);

        return new $class($order->get_id());
    }

    public function update_order_status($order) {
        if(empty($this->settings) || empty($this->settings['order_status'])) {
            WC_Gateway_Barion::log("settings['order_status'] is empty");
            return;
        }

        $should_update_status = $this->settings['order_status'] != 'automatic';
        $should_update_status = apply_filters('woocommerce_barion_should_update_order_status', $should_update_status, $order);

        if($should_update_status) {
            $order_status = apply_filters('woocommerce_barion_order_status', $this->settings['order_status'], $order);

            $order->update_status($order_status, __('Order status updated based on the settings.', 'pay-via-barion-for-woocommerce'));
        }
    }

    public function get_barion_pixel_id() {
        return $this->barion_pixel_id;
    }
}
