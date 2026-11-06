<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\E2E;

use KetaPay\WooCommerce\Orders\OrderSync;
use KetaPay\WooCommerce\Plugin;
use KetaPay\WooCommerce\Tests\E2E\Support\E2ETestCase;
use KetaPay\WooCommerce\Tests\E2E\Support\StagingApi;
use PHPUnit\Framework\Attributes\TestDox;

final class CheckoutTest extends E2ETestCase
{
    #[TestDox('[WE2E-001] placing an order creates a real checkout for its total and sends the buyer there, with the store as seller')]
    public function testPlacingAnOrder(): void
    {
        $order = $this->order();

        $result = $this->placeOrder($order);

        $session = $this->api->get('/checkout_sessions/' . $this->sessionId($order));
        self::assertSame(['success', StagingApi::text($session, 'url')], [$result['result'], $result['redirect'] ?? null]);
        self::assertSame(['open', 300_000], [$session['status'], $session['amount']]);
        self::assertEquals(['wc_order_id' => (string) $order->get_id(), 'wc_site' => Plugin::siteId()], $session['metadata']);
        self::assertSame('business', StagingApi::fields((array) $session['seller'])['type']);
        self::assertSame($order->get_billing_email(), StagingApi::fields((array) $session['buyer'])['email']);
        self::assertSame('pending', $this->reload($order)->get_status());
    }

    #[TestDox('[WE2E-008] changing an unpaid order closes the old checkout link and creates a new one')]
    public function testChangingAnOrder(): void
    {
        $order = $this->order();
        $this->placeOrder($order);
        $old = $this->sessionId($order);

        $order = $this->reload($order);
        $order->add_product($this->product('1000'));
        $order->calculate_totals();
        $order->save();
        $result = $this->placeOrder($order);

        $new = $this->sessionId($order);
        self::assertNotSame($old, $new);
        $session = $this->api->get("/checkout_sessions/$new");
        self::assertSame(['open', 400_000], [$session['status'], $session['amount']]);
        self::assertSame(StagingApi::text($session, 'url'), $result['redirect'] ?? null);
        self::assertSame('cancelled', $this->api->get("/checkout_sessions/$old")['status']);
    }

    #[TestDox('[WE2E-009] cancelling an unpaid order closes its checkout link')]
    public function testCancellingAnOrder(): void
    {
        $order = $this->order();
        $this->placeOrder($order);
        $session = $this->sessionId($order);

        $this->reload($order)->update_status('cancelled');

        self::assertSame('cancelled', $this->api->get("/checkout_sessions/$session")['status']);
    }

    #[TestDox('[WE2E-010] when the API refuses an order, the buyer sees the error, the order stays unpaid and no session is saved')]
    public function testARefusedOrder(): void
    {
        $order = $this->order('100'); // below the API's minimum escrow amount

        $result = $this->placeOrder($order);

        self::assertSame(['result' => 'failure'], $result);
        self::assertSame(1, wc_notice_count('error'));
        $order = $this->reload($order);
        self::assertSame('', $order->get_meta(OrderSync::META_SESSION));
        self::assertSame('pending', $order->get_status());
        self::assertFalse($order->is_paid());
    }
}
