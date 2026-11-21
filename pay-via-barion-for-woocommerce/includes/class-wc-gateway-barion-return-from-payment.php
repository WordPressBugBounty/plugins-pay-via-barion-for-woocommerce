<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Barion\Enumerations\PaymentStatus;

class WC_Gateway_Barion_Return_From_Payment {
    /**
     * Session key holding the cancellation notice we queued: the exact text,
     * plus when and in which request it was queued. The text lets the notice be
     * withdrawn again if it was never shown to the customer, the rest only
     * feeds the log.
     */
    const CANCEL_NOTICE_SESSION_KEY = 'barion_cancel_notice';

    /**
     * TEMPORARY, and the only switch behind the wide cleanup rule.
     *
     * Since when and on whose word: 2026-08-17, on the plugin owner's explicit
     * request after the fix had been rolled out to a merchant's live shop
     * ("igen, rakd be most tesztbe, hogy tudjunk tovabblepni. Majd kivesszuk").
     *
     * Why it had to be widened: the customers who are stuck RIGHT NOW got the
     * WooCommerce refusal notice from a version that still redirected them to
     * the cancel URL, and the same version's cleanup had already dropped our
     * own notice and left nothing else behind. Their session therefore carries
     * the refusal with NO trace of Barion at all, the narrow rule below cannot
     * reach it, and the merchant's log said exactly that: "Dropped 0 notice(s)
     * of ours" while one error notice kept blocking every block checkout order.
     * Updating the plugin alone does not free them.
     *
     * What it does: on the CHECKOUT PAGE only, and for that ONE WooCommerce
     * message only, the notice is dropped even without a trace of ours.
     *
     * The narrower, permanent rule to come back to: drop it only when the
     * session shows this customer really came back from a cancelled Barion
     * payment (is_our_cancel_refusal(), i.e. our record or our own notice).
     * Setting this to false restores that rule and nothing else changes. It can
     * go once the shops have updated and the affected sessions have expired --
     * a WooCommerce session lives two days.
     */
    const DROP_UNTRACED_CANCEL_REFUSAL = true;

    /**
     * The notice queue as it was when the checkout page started rendering.
     *
     * @var string|null
     */
    private static $queue_at_checkout_start = null;

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
        add_action('woocommerce_api_wc_gateway_barion_return_from_payment', array($this, 'redirect_to_order_received'));
        add_action('woocommerce_store_api_cart_errors', array($this, 'withdraw_unshown_cancel_notice'));
    }

    public function redirect_to_order_received() {
        $order = new WC_Order($_GET['order-id']);

        if(empty($order)) {
            WC_Gateway_Barion::log('Invalid Order Id: `' . $_GET['order-id'] . '`');

            return;
        }

        WC_Gateway_Barion::log(sprintf(
            '%s Customer returned from Barion. Order #%d, order status: %s. Notice queue on arrival: %s',
            self::log_prefix(),
            $order->get_id(),
            $order->get_status(),
            self::describe_queue()
        ));

        if($order->has_status('cancelled')) {
            $this->cancel_order($order);
            $this->add_cancel_notice($order);
            wp_redirect( wc_get_cart_url() );
            exit;
        }

        // The IPN (server-to-server) callback may be delayed, blocked or missing.
        // When the customer returns from a successful payment we verify the state
        // directly here and finalize the order as a fallback, so it is not left
        // unpaid if the IPN never arrives.
        if($order->has_status(array('pending', 'on-hold'))) {
            try {
                $payment_details = $this->barion_client->GetPaymentState($_GET['paymentId']);
            } catch (\Throwable $e) {
                // The Barion library only tolerates a JSON answer: when the API
                // replies with something else (an error page from the API or from
                // anything in front of it), json_decode() returns null and
                // GetPaymentStateResponseModel::fromJson() throws a TypeError.
                // Uncaught, that turned the customer's return page into an HTTP 500
                // "critical error" screen -- measured on the test shop on
                // 2026-08-17, twice on the very same paymentId that had answered
                // correctly seconds earlier. The payment state is simply unknown
                // here, so we do what the error branch below does and leave the
                // order to the IPN.
                WC_Gateway_Barion::log('GetPaymentState failed: ' . $e->getMessage());

                return;
            }

            if(!empty($payment_details->Errors)) {
                WC_Gateway_Barion::log('GetPaymentState returned errors. Payment details: ' . json_encode($payment_details));
                return;
            }

            // ->value, not the enum itself: PaymentStatus is a backed enum, and
            // handing it to sprintf() ends the request with a fatal error, on
            // the very page the customer comes back to. isset() covers the case
            // where the answer carried no Status at all: the property is typed,
            // so it can stay uninitialized.
            WC_Gateway_Barion::log(sprintf(
                '%s Barion payment state for order #%d: %s',
                self::log_prefix(),
                $order->get_id(),
                isset($payment_details->Status) ? $payment_details->Status->value : '(missing)'
            ));

            // Safeguard 1: the returned paymentId must match the one stored for
            // this order, otherwise an order could be finalized with a foreign
            // paymentId passed in the query string.
            if($_GET['paymentId'] != $this->gateway->get_barion_payment_id($order)) {
                WC_Gateway_Barion::log('Return blocked, Barion paymentId does not match. Order: ' . $order->get_id());

                wp_redirect($this->gateway->get_return_url($order));
                exit;
            }

            if($payment_details->Status == PaymentStatus::Canceled) {
                $this->cancel_order($order);
                $this->add_cancel_notice($order);

                wp_redirect( wc_get_cart_url() );
                exit;
            }

            if($payment_details->Status == PaymentStatus::Succeeded) {
                // Safeguard 2: idempotency. Skip if the payment was already
                // finalized (e.g. the IPN won the race), so payment_complete()
                // does not run twice for the same order. The check only holds
                // under the order lock and on a freshly read order: the $order
                // above was loaded before GetPaymentState(), see
                // WC_Gateway_Barion::lock_order().
                if($this->gateway->lock_order($order->get_id())) {
                    try {
                        $order = $this->gateway->get_fresh_order($order);

                        if(!$order->has_status(array('processing', 'completed'))
                            && !$order->meta_exists('_barion_payment_close')) {
                            $order->add_order_note(__('Payment succeeded via Barion (confirmed on return).', 'pay-via-barion-for-woocommerce'));
                            $order->update_meta_data('_barion_payment_close', 1);
                            $order->save();

                            $this->gateway->payment_complete($order, $this->find_transaction_id($payment_details, $order));
                        }
                    } finally {
                        $this->gateway->unlock_order($order->get_id());
                    }
                } else {
                    WC_Gateway_Barion::log('Return: order #' . $order->get_id() . ' is being finalised by another request, not finalising it again.');
                }
            }
        }

        wp_redirect($this->gateway->get_return_url($order));
        exit;
    }

    function find_transaction_id($payment_details, $order) {
        foreach($payment_details->Transactions as $transaction) {
            if($transaction->POSTransactionId == $order->get_id()) {
                return $transaction->TransactionId;
            }
        }

        return '';
    }

    /**
     * Cancels the order the customer walked away from, and frees the checkout
     * session for the next one.
     *
     * Until now this was left to WooCommerce: the customer was redirected to
     * $order->get_cancel_order_url_raw(), and WC_Form_Handler::cancel_order()
     * did the work. That detour is a race we lose. Barion fires the IPN and
     * redirects the browser at the same moment, and whenever the IPN gets there
     * first it cancels the order itself (class-wc-gateway-barion-ipn-handler.php:105),
     * so by the time the browser follows our redirect the order is no longer
     * "pending" or "failed" and WooCommerce answers with an ERROR notice
     * instead: "Your order can no longer be cancelled" (class-wc-form-handler.php:827-828).
     * That notice then sticks in the session and blocks every later block
     * checkout order exactly the way our own notice used to -- measured on a
     * merchant's live shop on 2026-08-17 (order #1867) and reproduced here.
     * Simply reloading the cart afterwards does it again, because the cancel
     * parameters stay in the address bar.
     *
     * Nothing is lost by doing it ourselves. Everything WooCommerce does in
     * that branch hangs off the status change, not off the form handler:
     * stock restore (wc-stock-functions.php:155), reserved stock release
     * (wc-stock-functions.php:488) and coupon usage counts
     * (wc-order-functions.php:1072) are all hooked on
     * woocommerce_order_status_cancelled. What is left is the session key and
     * the woocommerce_cancelled_order action (class-wc-form-handler.php:820
     * and :825), and both are done here.
     *
     * @param WC_Order $order
     */
    private function cancel_order( $order ) {
        // Same value WooCommerce writes (class-wc-form-handler.php:820), so the
        // next order the customer places is a new one and not an attempt to
        // resurrect this one.
        if ( WC()->session ) {
            WC()->session->set( 'order_awaiting_payment', false );
        }

        if ( $order->has_status( 'cancelled' ) ) {
            WC_Gateway_Barion::log( sprintf(
                '%s Order #%d was already cancelled (the IPN got here first), only the checkout session was freed.',
                self::log_prefix(),
                $order->get_id()
            ) );

            return;
        }

        $order->update_status( 'cancelled', __( 'Payment canceled via Barion.', 'pay-via-barion-for-woocommerce' ) );

        do_action( 'woocommerce_cancelled_order', $order->get_id() );

        WC_Gateway_Barion::log( sprintf(
            '%s Order #%d cancelled on the customer\'s return, checkout session freed.',
            self::log_prefix(),
            $order->get_id()
        ) );
    }

    /**
     * The message the customer gets when the payment was cancelled or declined.
     *
     * @return string
     */
    private static function cancel_notice_message() {
        return __( 'The card payment was cancelled or declined. Please try again.', 'pay-via-barion-for-woocommerce' );
    }

    /**
     * Queues the "payment cancelled" message and remembers it.
     *
     * The message is stored in the session as well so the notice can be taken
     * back out of the queue later without touching notices that belong to
     * WooCommerce or to another plugin.
     *
     * @param WC_Order|null $order The order the customer came back with.
     */
    private function add_cancel_notice( $order = null ) {
        $message = self::cancel_notice_message();

        wc_add_notice( $message, 'error' );

        if ( WC()->session ) {
            WC()->session->set( self::CANCEL_NOTICE_SESSION_KEY, array(
                'message' => $message,
                'queued'  => time(),
                'request' => self::request_tag(),
                'order'   => $order ? $order->get_id() : 0,
            ) );
        }

        WC_Gateway_Barion::log( sprintf(
            '%s Cancel notice queued%s. Notice queue now: %s',
            self::log_prefix(),
            $order ? ' for order #' . $order->get_id() : '',
            self::describe_queue()
        ) );
    }

    /**
     * Drops our cancellation notice when a block checkout order is placed.
     *
     * A queued WooCommerce notice is only consumed when the customer actually
     * renders a page that prints notices. Coming back from Barion they often do
     * not (browser back, closed tab, cached page), so the notice stays in the
     * session. WooCommerce then turns EVERY queued error notice into a hard cart
     * error in CartController::validate_cart(): the Store API place-order
     * request fails with 409 woocommerce_rest_cart_item_error before any gateway
     * code runs (hence the empty Barion log), and the conversion does not clear
     * the notice either, so the customer can never check out again.
     *
     * Withdrawing it here is safe: at this point the customer is placing a new
     * order, so a message about the previous, cancelled payment is stale anyway.
     */
    public function withdraw_unshown_cancel_notice() {
        if ( ! $this->is_store_api_place_order_request() || ! WC()->session ) {
            return;
        }

        $queue   = self::describe_queue();
        $tracked = self::tracked_notice();

        // No wide rule here: by this point the checkout page has had its own
        // chance to clean up, and a place-order request is the last place to
        // start removing messages the customer may never have seen.
        list( $removed, $traced, $untraced ) = self::remove_own_notices();

        if ( 0 === $removed && 0 === $traced && 0 === wc_notice_count( 'error' ) ) {
            return;
        }

        WC_Gateway_Barion::log( sprintf(
            '%s Store API place order. Notice queue: %s. Withdrew %d notice(s) of ours%s.%s %s',
            self::log_prefix(),
            $queue,
            $removed,
            self::describe_origin( $tracked ),
            self::describe_refusal_drop( $traced, $untraced ),
            self::describe_leftover_errors()
        ) );
    }

    /**
     * Takes note of the notice queue when the checkout page starts rendering,
     * and has whatever is left of OUR notices dropped once the page is done.
     *
     * Merchant report, 2026-08-17: "egyszeri megjelenés után dobja el (...)
     * amikor betölt a pénztár oldal, minden régebbit is töröljön". Two reasons
     * this is needed on top of the withdrawal above:
     *
     * - 3.10.2 queued the notice without any session marker, so a notice that
     *   is ALREADY stuck in a shopper's session (sessions live for two days)
     *   was invisible to the marker-based withdrawal, and the shopper kept
     *   getting the same message on every attempt even after the update.
     * - A notice nobody printed is stale by the time the checkout page loads
     *   again: it belongs to a payment the customer has already left behind.
     *
     * Cleaning up at `shutdown` and not right here is deliberate: a page that
     * does print notices (`wc_print_notices()`, e.g. the Store Notices block)
     * renders long before shutdown, so the customer still gets the message
     * exactly once, and only the leftover is dropped.
     */
    public static function schedule_checkout_notice_cleanup() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! WC()->session ) {
            return;
        }

        self::$queue_at_checkout_start = self::describe_queue();

        // Priority 5: WC_Session_Handler::save_data() runs on shutdown at 20,
        // and a change made after that would live for this request only.
        add_action( 'shutdown', array( __CLASS__, 'drop_stale_cancel_notices' ), 5 );
    }

    /**
     * Drops our leftover notices at the end of a checkout page render.
     */
    public static function drop_stale_cancel_notices() {
        if ( ! WC()->session ) {
            return;
        }

        $queue_after_render = self::describe_queue();
        $tracked            = self::tracked_notice();

        // The one place the temporary wide rule applies (see
        // DROP_UNTRACED_CANCEL_REFUSAL).
        list( $removed, $traced, $untraced ) = self::remove_own_notices( true );

        // Quiet unless there is something to report: a notice of ours, or an
        // error notice from somewhere else, which blocks the block checkout the
        // very same way and is exactly what we need to see in the log.
        if ( 0 === $removed && 0 === $traced && 0 === $untraced && 0 === wc_notice_count( 'error' ) ) {
            return;
        }

        WC_Gateway_Barion::log( sprintf(
            '%s Checkout page. Notice queue before render: %s. After render: %s. Dropped %d notice(s) of ours%s.%s %s',
            self::log_prefix(),
            null === self::$queue_at_checkout_start ? '(not recorded)' : self::$queue_at_checkout_start,
            $queue_after_render,
            $removed,
            self::describe_origin( $tracked ),
            self::describe_refusal_drop( $traced, $untraced ),
            self::describe_leftover_errors()
        ) );
    }

    /**
     * The part of the log line that reports the WooCommerce cancel refusals we
     * dropped, and on which of the two grounds.
     *
     * @param int $traced   Dropped because the session shows a cancelled Barion payment.
     * @param int $untraced Dropped by the temporary wide rule, with no such sign.
     * @return string
     */
    private static function describe_refusal_drop( $traced, $untraced ) {
        $parts = array();

        if ( $traced > 0 ) {
            $parts[] = sprintf(
                ' Dropped %d WooCommerce "order can no longer be cancelled" notice(s) tied to this customer\'s cancelled Barion payment.',
                $traced
            );
        }

        if ( $untraced > 0 ) {
            $parts[] = sprintf(
                ' Dropped %d WooCommerce "order can no longer be cancelled" notice(s) with NO Barion trace in the session, under the TEMPORARY wide rule of 2026-08-17 (DROP_UNTRACED_CANCEL_REFUSAL) that frees shoppers left stuck by an earlier version.',
                $untraced
            );
        }

        return implode( '', $parts );
    }

    /**
     * What is still in the error queue after we cleaned up after ourselves.
     *
     * Anything left blocks a block checkout order exactly the way our own
     * notice did, so this is the line that names the culprit.
     *
     * @return string
     */
    private static function describe_leftover_errors() {
        $left = wc_notice_count( 'error' );

        if ( 0 === $left ) {
            return 'No error notice left.';
        }

        return sprintf( '%d error notice(s) left, which will block a block checkout order.', $left );
    }

    /**
     * Removes every queued error notice that carries our message, plus the one
     * WooCommerce message our own cancel redirect used to provoke.
     *
     * Matching on the text and not on the session marker is what makes an
     * already stuck notice recoverable: 3.10.2 left no marker behind, and a
     * shop that updates the plugin still has those notices in live sessions.
     *
     * @param bool $allow_untraced Whether the temporary wide rule applies here
     *                             (see DROP_UNTRACED_CANCEL_REFUSAL).
     * @return int[] How many were removed: ours, cancel refusals traced back to
     *               us, and cancel refusals dropped without any trace.
     */
    private static function remove_own_notices( $allow_untraced = false ) {
        $notices = wc_get_notices();

        if ( empty( $notices['error'] ) ) {
            return array( 0, 0, 0 );
        }

        $ours     = 0;
        $traced   = 0;
        $untraced = 0;

        $kept = array_values( array_filter( $notices['error'], function( $notice ) use ( $allow_untraced, &$ours, &$traced, &$untraced ) {
            $text = isset( $notice['notice'] ) ? $notice['notice'] : '';

            if ( self::is_own_notice( $text ) ) {
                $ours++;

                return false;
            }

            if ( ! self::is_cancel_refusal_text( $text ) ) {
                return true;
            }

            if ( self::cancelled_payment_in_session() ) {
                $traced++;

                return false;
            }

            if ( $allow_untraced && self::DROP_UNTRACED_CANCEL_REFUSAL ) {
                $untraced++;

                return false;
            }

            return true;
        } ) );

        if ( 0 === $ours + $traced + $untraced ) {
            return array( 0, 0, 0 );
        }

        if ( empty( $kept ) ) {
            unset( $notices['error'] );
        } else {
            $notices['error'] = $kept;
        }

        wc_set_notices( $notices );

        return array( $ours, $traced, $untraced );
    }

    /**
     * True for WooCommerce's "cannot be cancelled any more" message, but only
     * when it can be traced back to a Barion payment this customer cancelled.
     *
     * The message itself belongs to WooCommerce
     * (class-wc-form-handler.php:827-828) and is a legitimate answer when a
     * customer clicks Cancel on an order in My Account that has meanwhile moved
     * on. What made it OUR problem is that the plugin used to send every
     * cancelled payment through that same cancel URL, so a shopper got the
     * message without ever asking for anything -- and being an error notice, it
     * then blocked their block checkout for as long as the session lived (two
     * days). We stopped handing out that URL, but the shops that ran the
     * previous versions still have those notices in live sessions, and clearing
     * them is the only way those customers can check out again.
     *
     * Hence the narrow condition: this is dropped only in a session where we
     * queued a cancellation message of our own, which is exactly the session of
     * a customer who came back from a cancelled Barion payment. Everywhere else
     * WooCommerce's message is left alone, and so is every other error notice
     * (out of stock, invalid coupon), which we must never swallow.
     *
     * @param string $text
     * @return bool
     */
    private static function is_our_cancel_refusal( $text ) {
        return self::is_cancel_refusal_text( $text ) && self::cancelled_payment_in_session();
    }

    /**
     * True for WooCommerce's "cannot be cancelled any more" message, whoever
     * provoked it. THE ONE text this plugin ever removes on WooCommerce's
     * behalf; every other error notice (out of stock, invalid coupon) is left
     * where it is.
     *
     * @param string $text
     * @return bool
     */
    private static function is_cancel_refusal_text( $text ) {
        $text = wp_strip_all_tags( (string) $text );

        // Both the translated and the original wording: the customer may have
        // been sent to the cancel URL in another language than the one the
        // checkout page is loaded in.
        $refusals = array(
            __( 'Your order can no longer be cancelled. Please contact us if you need assistance.', 'woocommerce' ),
            'Your order can no longer be cancelled. Please contact us if you need assistance.',
        );

        foreach ( $refusals as $refusal ) {
            if ( $text === wp_strip_all_tags( $refusal ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when this session belongs to a customer who came back from a
     * cancelled Barion payment.
     *
     * @return bool
     */
    private static function cancelled_payment_in_session() {
        if ( ! empty( self::tracked_notice() ) ) {
            return true;
        }

        $notices = wc_get_notices( 'error' );

        foreach ( (array) $notices as $notice ) {
            if ( self::is_own_notice( isset( $notice['notice'] ) ? $notice['notice'] : '' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the given notice text is the message we queue ourselves.
     *
     * @param string $text
     * @return bool
     */
    private static function is_own_notice( $text ) {
        $text = wp_strip_all_tags( (string) $text );

        foreach ( self::own_notice_texts() as $own ) {
            if ( $text === wp_strip_all_tags( $own ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Our message in the current locale, plus the text we actually queued.
     *
     * The two can differ on a multilingual shop, where the customer may come
     * back from Barion in one language and reach the checkout in another.
     *
     * @return string[]
     */
    private static function own_notice_texts() {
        $texts   = array( self::cancel_notice_message() );
        $tracked = self::tracked_notice();

        if ( ! empty( $tracked['message'] ) ) {
            $texts[] = $tracked['message'];
        }

        return array_unique( $texts );
    }

    /**
     * What we know about the notice we queued, if any.
     *
     * The record is deliberately NOT cleared once the notice is gone: it is
     * what tells a later request that this session belongs to a customer who
     * came back from a cancelled Barion payment, and the fallout of that
     * (see is_our_cancel_refusal()) can outlive the notice itself. It goes away
     * with the session, and a new cancellation overwrites it.
     *
     * @return array
     */
    private static function tracked_notice() {
        if ( ! WC()->session ) {
            return array();
        }

        $tracked = WC()->session->get( self::CANCEL_NOTICE_SESSION_KEY );

        // 3.10.2 stored the bare message text under this key.
        if ( is_string( $tracked ) && '' !== $tracked ) {
            return array( 'message' => $tracked );
        }

        return is_array( $tracked ) ? $tracked : array();
    }

    /**
     * Where a tracked notice came from, for the log.
     *
     * @param array $tracked
     * @return string
     */
    private static function describe_origin( $tracked ) {
        if ( empty( $tracked['queued'] ) ) {
            return '';
        }

        return sprintf(
            ' (ours was queued %s UTC in request %s%s)',
            gmdate( 'Y-m-d H:i:s', (int) $tracked['queued'] ),
            empty( $tracked['request'] ) ? '?' : $tracked['request'],
            empty( $tracked['order'] ) ? '' : ' for order #' . (int) $tracked['order']
        );
    }

    /**
     * Every queued notice, one by one: type, whose it is, and its text.
     *
     * This is the line that answers "what is holding up this checkout": an
     * error notice from any plugin blocks a block checkout order the same way
     * ours did, and without this the Barion log shows nothing about it.
     *
     * @return string
     */
    private static function describe_queue() {
        if ( ! function_exists( 'wc_get_notices' ) || ! WC()->session ) {
            return '(no session)';
        }

        $parts = array();

        foreach ( wc_get_notices() as $type => $items ) {
            foreach ( (array) $items as $item ) {
                $text = isset( $item['notice'] ) ? $item['notice'] : '';

                $parts[] = sprintf(
                    '%s/%s "%s"',
                    $type,
                    self::describe_owner( $text ),
                    self::shorten_for_log( $text )
                );
            }
        }

        return empty( $parts ) ? '(empty)' : implode( ' + ', $parts );
    }

    /**
     * Whose notice this is, as far as the log is concerned.
     *
     * @param string $text
     * @return string
     */
    private static function describe_owner( $text ) {
        if ( self::is_own_notice( $text ) ) {
            return 'barion';
        }

        if ( self::is_our_cancel_refusal( $text ) ) {
            return 'woocommerce-cancel-refusal-after-barion';
        }

        if ( self::is_cancel_refusal_text( $text ) ) {
            return 'woocommerce-cancel-refusal-untraced';
        }

        return 'other';
    }

    /**
     * A notice text made fit for a log file on a live shop.
     *
     * @param string $text
     * @return string
     */
    private static function shorten_for_log( $text ) {
        $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
        // Notices are messages, not customer data, but a failed login on the
        // checkout page puts an e-mail address into one, and that has no
        // business being written to a merchant's log file.
        $text = preg_replace( '/[^\s@]+@[^\s@]+\.[^\s@]+/u', '[email]', $text );

        return mb_strlen( $text ) > 90 ? mb_substr( $text, 0, 90 ) . '...' : $text;
    }

    /**
     * Prefix tying every line of one request together, so the log shows in
     * which request a notice appeared and in which one it went away.
     *
     * @return string
     */
    private static function log_prefix() {
        return sprintf( '[notices %s] %s', self::request_tag(), self::request_line() );
    }

    private static function request_tag() {
        static $tag = null;

        if ( null === $tag ) {
            $tag = substr( md5( uniqid( '', true ) ), 0, 6 );
        }

        return $tag;
    }

    /**
     * Method and path of the current request. The query string is left out on
     * purpose (it can carry anything the shopper typed); the handler behind
     * `wc-api` is not, because that is what identifies the return call.
     *
     * @return string
     */
    private static function request_line() {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '?';
        $path   = isset( $_SERVER['REQUEST_URI'] ) ? strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) : '';
        $line   = $method . ' ' . $path;

        if ( isset( $_GET['wc-api'] ) ) {
            $line .= ' [wc-api ' . sanitize_text_field( wp_unslash( $_GET['wc-api'] ) ) . ']';
        }

        return $line;
    }

    /**
     * True while the Store API (block checkout) "place order" request is handled.
     *
     * `woocommerce_store_api_cart_errors` also fires on plain cart/checkout page
     * views, where the notice still has to be shown, so the route is checked
     * instead of relying on the hook alone.
     *
     * @return bool
     */
    private function is_store_api_place_order_request() {
        if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
            return false;
        }

        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return false;
        }

        $route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';

        return '/wc/store/v1/checkout' === untrailingslashit( $route );
    }
}