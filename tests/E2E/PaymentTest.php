<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\E2E;

use KetaPay\WooCommerce\Orders\OrderSync;
use KetaPay\WooCommerce\Tests\E2E\Support\E2ETestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class PaymentTest extends E2ETestCase
{
    protected function tearDown(): void
    {
        unset($_GET['checkout_session_id'], $_GET['key']);
        $wp = $GLOBALS['wp'] ?? null;
        $query = $GLOBALS['wp_query'] ?? null;
        if ($wp instanceof \WP && $query instanceof \WP_Query) {
            unset($wp->query_vars['order-received']);
            $query->set('order-received', '');
        }
        parent::tearDown();
    }

    #[TestDox('[WE2E-002] after paying, a buyer returning to the store sees the order as Processing, before any webhook')]
    public function testReturningBuyer(): void
    {
        $order = $this->order();
        $escrowId = $this->pay($order);

        // What WordPress sets when it routes /checkout/order-received/<id>/
        $wp = $GLOBALS['wp'] ?? null;
        $query = $GLOBALS['wp_query'] ?? null;
        self::assertInstanceOf(\WP::class, $wp);
        self::assertInstanceOf(\WP_Query::class, $query);
        $wp->query_vars['order-received'] = (string) $order->get_id();
        $query->set('order-received', (string) $order->get_id());
        $_GET['checkout_session_id'] = $this->sessionId($order);
        $_GET['key'] = $order->get_order_key();
        OrderSync::confirmReturn();

        $order = $this->reload($order);
        self::assertSame(['processing', $escrowId], [$order->get_status(), $order->get_transaction_id()]);
    }

    #[TestDox('[WE2E-003] the real escrow.funded event moves an unpaid order to Processing, and delivering it again changes nothing')]
    public function testFundedEvent(): void
    {
        $order = $this->order();
        $escrowId = $this->pay($order);
        $event = $this->realEvent('escrow.funded', $escrowId);

        $this->deliver($event);

        $order = $this->reload($order);
        self::assertSame(['processing', $escrowId], [$order->get_status(), $order->get_meta(OrderSync::META_ESCROW)]);
        self::assertTrue($order->is_paid());
        $notes = $this->notes($order);

        $this->deliver($event);

        self::assertSame('processing', $this->reload($order)->get_status());
        self::assertSame($notes, $this->notes($order));
    }

    #[TestDox('[WE2E-004] an order total with kobo matches the funded escrow exactly, so the order is not held for a mismatch')]
    public function testKoboTotal(): void
    {
        $order = $this->order('3000.55');
        $escrowId = $this->pay($order);
        self::assertSame(300_055, $this->api->get("/escrows/$escrowId")['amount']);

        $this->deliver($this->realEvent('escrow.funded', $escrowId));

        self::assertSame('processing', $this->reload($order)->get_status());
    }
}
