<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Integration;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use KetaPay\WooCommerce\Blocks\PaymentMethod;
use KetaPay\WooCommerce\Orders\OrderSync;
use KetaPay\WooCommerce\Plugin;
use KetaPay\WooCommerce\Tests\Integration\Support\IntegrationTestCase;

final class CheckoutTest extends IntegrationTestCase
{
    public function testSendsTheBuyerToKetaPayCheckout(): void
    {
        $order = $this->order();
        $this->api->on('POST', '/checkout_sessions', 200, $this->session($order));

        $result = $this->gateway()->process_payment($order->get_id());

        self::assertSame(['result' => 'success', 'redirect' => 'https://checkout.ketapay.com/cs_' . $order->get_id()], $result);
        self::assertSame(['POST /checkout_sessions'], $this->api->calls());

        $body = $this->api->body(0);
        self::assertSame(4_700_000, $body['amount']);
        self::assertSame(['email' => 'buyer@example.com', 'phone' => '+2348012345678'], $body['buyer']);
        self::assertSame(['email' => 'shop@example.com'], $body['seller']);
        self::assertSame((string) $order->get_id(), $body['external_reference']);
        self::assertSame(['wc_order_id' => (string) $order->get_id(), 'wc_site' => Plugin::siteId()], $body['metadata']);
        self::assertIsString($body['description']);
        self::assertStringContainsString('2 × Ankara shirt: ₦47,000.00', $body['description']);
        self::assertIsString($body['return_url']);
        self::assertStringContainsString('order-received', $body['return_url']);

        $request = $this->api->requests[0];
        self::assertSame('Bearer sk_test_fake', $request->headers['Authorization']);
        self::assertNotEmpty($request->headers['Idempotency-Key']);
        self::assertStringContainsString('ketapay-woocommerce/' . Plugin::VERSION, $request->headers['User-Agent']);

        $order = $this->reload($order);
        self::assertSame('cs_' . $order->get_id(), $order->get_meta(OrderSync::META_SESSION));
        self::assertSame('pending', $order->get_status());
        self::assertFalse($order->is_paid());
    }

    public function testPayingAgainReusesTheOpenLink(): void
    {
        $order = $this->order();
        $order->update_meta_data(OrderSync::META_SESSION, 'cs_' . $order->get_id());
        $order->save();
        $this->api->on('GET', '/checkout_sessions/cs_' . $order->get_id(), 200, $this->session($order));

        $result = $this->gateway()->process_payment($order->get_id());

        self::assertSame('https://checkout.ketapay.com/cs_' . $order->get_id(), $result['redirect'] ?? null);
        self::assertSame(['GET /checkout_sessions/cs_' . $order->get_id()], $this->api->calls());
    }

    public function testAChangedOrderClosesTheOldLinkAndGetsANewOne(): void
    {
        $order = $this->order();
        $old = 'cs_' . $order->get_id();
        $order->update_meta_data(OrderSync::META_SESSION, $old);
        $order->save();
        $this->api
            ->on('GET', "/checkout_sessions/$old", 200, $this->session($order, ['amount' => 100]))
            ->on('POST', "/checkout_sessions/$old/cancel", 200, $this->session($order, ['status' => 'cancelled']))
            ->on('POST', '/checkout_sessions', 200, $this->session($order, ['id' => 'cs_new', 'url' => 'https://checkout.ketapay.com/cs_new']));

        $result = $this->gateway()->process_payment($order->get_id());

        self::assertSame('https://checkout.ketapay.com/cs_new', $result['redirect'] ?? null);
        self::assertSame(["GET /checkout_sessions/$old", "POST /checkout_sessions/$old/cancel", 'POST /checkout_sessions'], $this->api->calls());
        self::assertSame('cs_new', $this->reload($order)->get_meta(OrderSync::META_SESSION));
    }

    public function testAnAlreadyPaidLinkSendsTheBuyerToTheirOrderNotToPayAgain(): void
    {
        $order = $this->order();
        $session = 'cs_' . $order->get_id();
        $order->update_meta_data(OrderSync::META_SESSION, $session);
        $order->save();
        $this->api->on('GET', "/checkout_sessions/$session", 200, $this->session($order, ['status' => 'complete', 'escrow_id' => 'esc_1']));

        $result = $this->gateway()->process_payment($order->get_id());

        self::assertIsString($result['redirect'] ?? null);
        self::assertStringContainsString("checkout_session_id=$session", $result['redirect']);
        self::assertNotContains('POST /checkout_sessions', $this->api->calls());
    }

    public function testAnApiErrorShowsTheBuyerAMessageAndKeepsTheOrderUnpaid(): void
    {
        $order = $this->order();
        $this->api->on('POST', '/checkout_sessions', 400, [
            'error' => ['type' => 'invalid_request_error', 'code' => 'party_identity_conflict', 'message' => 'Email and phone belong to different accounts', 'param' => 'buyer'],
        ]);

        $result = $this->gateway()->process_payment($order->get_id());

        self::assertSame(['result' => 'failure'], $result);
        self::assertSame(1, wc_notice_count('error'));
        $order = $this->reload($order);
        self::assertSame('', $order->get_meta(OrderSync::META_SESSION));
        self::assertSame('pending', $order->get_status());
    }

    public function testHiddenAtCheckoutUntilSetUp(): void
    {
        self::assertTrue($this->gateway()->is_available());

        update_option('woocommerce_ketapay_settings', ['enabled' => 'yes', 'secret_key' => 'sk_test_x']);
        WC()->payment_gateways()->init();
        self::assertFalse($this->gateway()->is_available(), 'no seller email or webhook secret');
    }

    public function testHiddenForStoresNotInNaira(): void
    {
        update_option('woocommerce_currency', 'USD');

        self::assertFalse($this->gateway()->is_available());
    }

    public function testShownInTheBlockCheckout(): void
    {
        $container = \Automattic\WooCommerce\Blocks\Package::container();
        self::assertIsObject($container);
        self::assertTrue(method_exists($container, 'get'));
        $registry = $container->get(PaymentMethodRegistry::class);
        self::assertInstanceOf(PaymentMethodRegistry::class, $registry);
        $method = $registry->get_registered(Plugin::GATEWAY_ID);

        self::assertInstanceOf(PaymentMethod::class, $method);
        self::assertTrue($method->is_active());
        self::assertSame(['ketapay-wc-blocks'], $method->get_payment_method_script_handles());
        self::assertSame('Pay with KetaPay escrow', $method->get_payment_method_data()['title']);
    }
}
