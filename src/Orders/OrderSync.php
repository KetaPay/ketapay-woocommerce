<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Orders;

use KetaPay\Errors\KetaPayError;
use KetaPay\Models\Event;
use KetaPay\WooCommerce\Checkout\Money;
use KetaPay\WooCommerce\Checkout\SessionParams;
use KetaPay\WooCommerce\Plugin;

/** Applies KetaPay events to WooCommerce orders, from webhooks and from the buyer's return. */
final class OrderSync
{
    public const META_SESSION = '_ketapay_checkout_session_id';
    public const META_ESCROW = '_ketapay_escrow_id';
    private const META_EVENTS = '_ketapay_event_ids';
    /** Event ids remembered per order, to skip webhook retries. */
    private const EVENTS_KEPT = 50;

    /** @return string what happened, for the log */
    public static function handle(Event $event): string
    {
        $object = $event->data->object->toArray();
        $order = self::orderFor($object);
        if ($order === null) {
            return 'ignored: not an order from this store';
        }
        $milestoneId = $event->data->milestone_id;
        $update = EventRules::decide($event->type, $object, is_string($milestoneId) ? $milestoneId : null, self::state($order));

        return self::apply($order, $update, $event->id);
    }

    /**
     * The buyer is back on the order-received page with `?checkout_session_id=`. If the webhook
     * hasn't arrived yet, ask KetaPay directly, so the buyer sees their order as paid.
     */
    public static function confirmReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a redirect from KetaPay; the order key is the check
        $sessionId = $_GET['checkout_session_id'] ?? null;
        $key = $_GET['key'] ?? null;
        if (!is_string($sessionId) || !is_string($key) || !is_wc_endpoint_url('order-received')) {
            return;
        }
        $sessionId = sanitize_text_field(wp_unslash($sessionId));
        $key = sanitize_text_field(wp_unslash($key));
        // phpcs:enable

        $orderId = get_query_var('order-received');
        $order = is_numeric($orderId) ? wc_get_order(absint($orderId)) : false;
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== Plugin::GATEWAY_ID || $order->is_paid()
            || !hash_equals($order->get_order_key(), $key) || $sessionId !== $order->get_meta(self::META_SESSION)) {
            return;
        }

        try {
            $client = Plugin::client();
            $session = $client->checkoutSessions->retrieve($sessionId);
            if ($session->status !== 'complete' || !is_string($session->escrow_id)) {
                return;
            }
            $escrow = $client->escrows->retrieve($session->escrow_id)->toArray();
            self::apply($order, EventRules::decide('escrow.funded', $escrow, null, self::state($order)), 'return:' . $sessionId);
        } catch (KetaPayError $e) {
            // The webhook still updates the order; the buyer just sees "pending" for now.
            Plugin::log('warning', 'Could not confirm the payment on return: ' . $e->getMessage(), ['order_id' => $order->get_id()]);
        }
    }

    /** An unpaid order was cancelled in WooCommerce: close its checkout link so it can't be paid later. */
    public static function cancelCheckout(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== Plugin::GATEWAY_ID || $order->is_paid()) {
            return;
        }
        $sessionId = $order->get_meta(self::META_SESSION);
        if (!is_string($sessionId) || $sessionId === '') {
            return;
        }
        try {
            Plugin::client()->checkoutSessions->cancel($sessionId);
        } catch (KetaPayError $e) {
            // Already expired, cancelled or paid: nothing to close. A payment is still recorded by its webhook.
            Plugin::log('info', 'Checkout link not cancelled: ' . $e->getMessage(), ['order_id' => $orderId]);
        }
    }

    public static function state(\WC_Order $order): OrderState
    {
        $session = $order->get_meta(self::META_SESSION);
        $escrow = $order->get_meta(self::META_ESCROW);

        return new OrderState(
            status: $order->get_status(),
            isPaid: $order->is_paid(),
            totalKobo: Money::toKobo($order->get_total()),
            checkoutSessionId: is_string($session) && $session !== '' ? $session : null,
            escrowId: is_string($escrow) && $escrow !== '' ? $escrow : null,
        );
    }

    /** @return string what happened, for the log */
    public static function apply(\WC_Order $order, OrderUpdate $update, string $eventId): string
    {
        $seen = $order->get_meta(self::META_EVENTS);
        $seen = is_array($seen) ? array_values(array_filter($seen, 'is_string')) : [];
        if (in_array($eventId, $seen, true)) {
            return 'duplicate';
        }

        if ($update->markPaidWithEscrow !== null) {
            $order->update_meta_data(self::META_ESCROW, $update->markPaidWithEscrow);
            $order->payment_complete($update->markPaidWithEscrow);
        }
        foreach ($update->notes as $note) {
            $order->add_order_note($note);
        }
        if ($update->status !== null && $order->get_status() !== $update->status) {
            $order->update_status($update->status);
        }

        $seen[] = $eventId;
        $order->update_meta_data(self::META_EVENTS, array_slice($seen, -self::EVENTS_KEPT));
        $order->save();

        return $update->isEmpty() ? 'no change' : 'applied';
    }

    /**
     * Escrows and checkout sessions carry the order id in their metadata. Disputes, payouts, refunds
     * and chargebacks carry the escrow id: found through the order that recorded it, or, when that
     * event came first, through the escrow's own metadata.
     *
     * @param array<string, mixed> $object
     */
    private static function orderFor(array $object): ?\WC_Order
    {
        $kind = $object['object'] ?? null;
        if ($kind === 'escrow' || $kind === 'checkout_session') {
            return self::orderFromMetadata($object);
        }
        $escrowId = $object['escrow_id'] ?? null;
        if (!is_string($escrowId) || $escrowId === '') {
            return null;
        }
        $orders = wc_get_orders([
            'limit' => 1,
            'payment_method' => Plugin::GATEWAY_ID,
            'meta_query' => [['key' => self::META_ESCROW, 'value' => $escrowId]], // phpcs:ignore WordPress.DB.SlowDBQuery
        ]);
        if (is_array($orders) && ($orders[0] ?? null) instanceof \WC_Order) {
            return $orders[0];
        }

        return self::orderFromMetadata(Plugin::client()->escrows->retrieve($escrowId)->toArray());
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function orderFromMetadata(array $object): ?\WC_Order
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $orderId = $metadata[SessionParams::META_ORDER_ID] ?? null;
        if (($metadata[SessionParams::META_SITE] ?? null) !== Plugin::siteId() || !is_string($orderId) || !ctype_digit($orderId)) {
            return null;
        }
        $order = wc_get_order((int) $orderId);

        return $order instanceof \WC_Order && $order->get_payment_method() === Plugin::GATEWAY_ID ? $order : null;
    }
}
