<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Barion\Enumerations\PaymentStatus;

class WC_Gateway_Barion_IPN_Handler {
	 /**
     * @var BarionClient
     */
    private $barion_client;
    /**
     * @var WCGateway
     */
    private $gateway;
       public function __construct($barion_client, $gateway) {
        $this->barion_client = $barion_client;
        $this->gateway = $gateway;
        add_action('woocommerce_api_wc_gateway_barion', array($this, 'check_barion_ipn'));
    }

    public function check_barion_ipn() {
        if(empty($_GET) || empty($_GET['paymentId']))
            exit;

        try {
            $payment_details = $this->barion_client->GetPaymentState($_GET['paymentId']);
        } catch (\Throwable $e) {
            // Same failure as on the return page: an answer from the Barion API
            // that is not JSON makes the library throw a TypeError, and uncaught
            // it ended the callback with an HTTP 500 (measured on the test shop
            // on 2026-11-20). The payment state is unknown, so this is handled
            // like the error branch below.
            $this->gateway::log('GetPaymentState failed: ' . $e->getMessage());

            exit;
        }

        if(!empty($payment_details->Errors)) {
            $this->gateway::log('GetPaymentState returned errors. Payment details: ' . json_encode($payment_details));

            exit;
        }
$args = array(
    'numberposts' => 1,
    'meta_key'    => '_order_number',
    'meta_value'  => $payment_details->PaymentRequestId,
    'post_type'   => 'shop_order',
    'post_status' => 'any'
);

$orders = get_posts($args);

if ($orders) {
        $order_id = $orders[0]->ID;

    // A WC_Order objektum létrehozása a rendelés azonosító alapján
    $order = wc_get_order($order_id);
    
} else {
   // new WC_Order() throws for an id without an order (a deleted test order,
   // measured on the test shop on 2026-11-20), which ended the callback with
   // an HTTP 500 and Barion kept retrying; wc_get_order() returns false.
   $order = wc_get_order($payment_details->PaymentRequestId);
}

        if(empty($order)) {
            $this->gateway::log('IPN: no order found for PaymentRequestId "' . $payment_details->PaymentRequestId . '", paymentId: ' . $_GET['paymentId'] . '. Payment details: ' . json_encode($payment_details));
            exit;
        }

        // An exception while processing the callback of an existing order is
        // recorded in the log and on the order instead of ending in an HTTP 500.
        try {
        $this->gateway::log("IPN called, orderid: ".$order->get_id());
		
		if ($_GET['paymentId'] !=$this->gateway->get_barion_payment_id($order)) {
						$this->gateway::log("IPN blocked, Barion id is not match.");
			exit;
		}
				
        $order->add_order_note(__('Barion callback received.', 'pay-via-barion-for-woocommerce') . ' paymentId: "' . $_GET['paymentId'] . '"');
if ($order->meta_exists('_barion_payment_close')) {
	            $order->add_order_note(__('Barion callback ignored, the payment closed.', 'pay-via-barion-for-woocommerce'));
				$this->gateway::log("IPN ignored, order closed");
				            exit;
}
        if(apply_filters('woocommerce_barion_custom_callback_handler', false, $order, $payment_details)) {
            $order->add_order_note(__('Barion callback was handled by a custom handler.', 'pay-via-barion-for-woocommerce'));
            exit;
        }
$payment_method = $order->get_payment_method();
 if($payment_method !='barion') {
            $order->add_order_note(__('Barion callback ignored as the user has chosen another payment method.', 'pay-via-barion-for-woocommerce'));
            exit;
        }

        if($order->has_status(array('processing', 'completed'))) {
            $order->add_order_note(__('Barion callback ignored as the payment was already completed.', 'pay-via-barion-for-woocommerce'));
            exit;
        }

        if($payment_details->Status == PaymentStatus::Succeeded) {
            if($order->has_status($this->gateway->get_successfull_status())) {
                exit;
            }

            // The checks above ran on the order loaded before GetPaymentState(),
            // so they are repeated under the order lock on a freshly read order:
            // the return page may have finalised it meanwhile, see
            // WC_Gateway_Barion::lock_order().
            if(!$this->gateway->lock_order($order->get_id())) {
                $this->gateway::log('IPN: order #' . $order->get_id() . ' is being finalised by another request, not finalising it again.');
                exit;
            }

            try {
                $order = $this->gateway->get_fresh_order($order);

                if($order->has_status(array('processing', 'completed'))
                    || $order->has_status($this->gateway->get_successfull_status())
                    || $order->meta_exists('_barion_payment_close')) {
                    $order->add_order_note(__('Barion callback ignored as the payment was already completed.', 'pay-via-barion-for-woocommerce'));
                } else {
            $order->add_order_note(__('Payment succeeded via Barion.', 'pay-via-barion-for-woocommerce'));
			if ($order->meta_exists('_barion_payment_close')) {
												$order->update_meta_data("_barion_payment_close", 1);						
																							} else {
						$order->add_meta_data("_barion_payment_close", 1);
																	}
				$order->save();

            $this->gateway->payment_complete($order, $this->find_transaction_id($payment_details, $order));
                }
            } finally {
                $this->gateway->unlock_order($order->get_id());
            }

            exit;
        }

        if($payment_details->Status == PaymentStatus::Canceled) {
						if (empty($this->gateway->get_rejected_status()) ||$this->gateway->get_rejected_status() =='no') {
            $order->update_status('cancelled', __('Payment canceled via Barion.', 'pay-via-barion-for-woocommerce'));
			} else {
				$order->update_status($this->gateway->get_rejected_status(), __('Payment changed via Barion.', 'pay-via-barion-for-woocommerce'));
			}

            exit;
        }

        if($payment_details->Status == PaymentStatus::Expired) {
			if (empty($this->gateway->get_expired_status()) ||$this->gateway->get_expired_status() =='no') {
            $order->update_status('cancelled', __('Payment is expired (customer progressed to Barion, but then left the page without paying).', 'pay-via-barion-for-woocommerce'));
			} else {
				$order->update_status($this->gateway->get_expired_status(), __('Payment is expired (customer progressed to Barion, but then left the page without paying).', 'pay-via-barion-for-woocommerce'));
			}
            

            exit;
        }

        $order->update_status('failed', __('Payment failed via Barion.', 'pay-via-barion-for-woocommerce'));
        $this->gateway::log('Payment failed. Payment details: ' . json_encode($payment_details));
		exit;
        } catch (\Throwable $e) {
            $this->gateway::log('IPN processing failed for order #' . $order->get_id() . ', paymentId: ' . $_GET['paymentId'] . ': ' . $e->getMessage());
            $order->add_order_note(__('Barion callback processing failed.', 'pay-via-barion-for-woocommerce') . ' ' . $e->getMessage());

            exit;
        }
    }

    function find_transaction_id($payment_details, $order) {
        foreach($payment_details->Transactions as $transaction) {
            if($transaction->POSTransactionId == $order->get_id()) {
                return $transaction->TransactionId;
            }
        }
    }
}
