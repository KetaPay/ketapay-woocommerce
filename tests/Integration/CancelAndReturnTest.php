<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Integration;

use KetaPay\WooCommerce\Orders\OrderSync;
use KetaPay\WooCommerce\Tests\Integration\Support\IntegrationTestCase;

final class CancelAndReturnTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        unset($_GET['checkout_session_id'], $_GET['key']);
        self::setOrderReceived('');
        parent::tearDown();
    }

    /** What WordPress sets when it routes /checkout/order-received/<id>/. */
    private static function setOrderReceived(string $orderId): void
    {
        $wp = $GLOBALS['wp'] ?? null;
        $query = $GLOBALS['wp_query'] ?? null;
        self::assertInstanceOf(\WP::class, $wp);
        self::assertInstanceOf(\WP_Query::class, $query);
        if ($orderId === '') {
            unset($wp->query_vars['order-received']);
        } else {
            $wp->query_vars['order-received'] = $orderId;
        }
        $query->set('order-received', $orderId);
    }

    private function returnTo(\WC_Order $order, string $sessionId, ?string $key = null): void
    {
        self::setOrderReceived((string) $order->get_id());
        $_GET['checkout_session_id'] = $sessionId;
        $_GET['key'] = $key ?? $order->get_order_key();

        OrderSync::confirmReturn();
    }

    public function testCancellingAnUnpaidOrderClosesItsCheckoutLink(): void
    {
        $order = $this->order();
        $session = 'cs_' . $order->get_id();
        $order->update_meta_data(OrderSync::META_SESSION, $session);
        $order->save();
        $this->api->on('POST', "/checkout_sessions/$session/cancel", 200, $this->session($order, ['status' => 'cancelled']));

        $order->update_status('cancelled');

        self::assertSame(["POST /checkout_sessions/$session/cancel"], $this->api->calls());
    }

    public function testReturningBuyerSeesTheOrderPaidBeforeTheWebhookArrives(): void
    {
        $order = $this->order();
        $session = 'cs_' . $order->get_id();
        $order->update_meta_data(OrderSync::META_SESSION, $session);
        $order->save();
        $this->api
            ->on('GET', "/checkout_sessions/$session", 200, $this->session($order, ['status' => 'complete', 'escrow_id' => 'esc_' . $order->get_id()]))
            ->on('GET', '/escrows/esc_' . $order->get_id(), 200, $this->escrow($order));

        $this->returnTo($order, $session);

        $order = $this->reload($order);
        self::assertSame('processing', $order->get_status());
        self::assertSame('esc_' . $order->get_id(), $order->get_transaction_id());

        // The webhook that follows is a no-op.
        $notes = count($this->notes($order));
        $this->webhook('escrow.funded', $this->escrow($order));
        self::assertCount($notes, $this->notes($this->reload($order)));
    }

    public function testAReturnWithTheWrongOrderKeyIsIgnored(): void
    {
        $order = $this->order();
        $order->update_meta_data(OrderSync::META_SESSION, 'cs_' . $order->get_id());
        $order->save();

        $this->returnTo($order, 'cs_' . $order->get_id(), 'wc_order_wrong');

        self::assertSame([], $this->api->calls());
    }

    public function testAReturnWithAnotherSessionIdIsIgnored(): void
    {
        $order = $this->order();
        $order->update_meta_data(OrderSync::META_SESSION, 'cs_' . $order->get_id());
        $order->save();

        $this->returnTo($order, 'cs_someone_elses');

        self::assertSame([], $this->api->calls());
    }

    public function testAnUnpaidSessionOnReturnLeavesTheOrderPending(): void
    {
        $order = $this->order();
        $session = 'cs_' . $order->get_id();
        $order->update_meta_data(OrderSync::META_SESSION, $session);
        $order->save();
        $this->api->on('GET', "/checkout_sessions/$session", 200, $this->session($order));

        $this->returnTo($order, $session);

        self::assertSame('pending', $this->reload($order)->get_status());
    }
}
