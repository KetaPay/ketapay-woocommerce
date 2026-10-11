<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Integration\Support;

use KetaPay\WooCommerce\Plugin;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected const WEBHOOK_SECRET = 'whsec_integration';

    protected FakeKetaPay $api;

    protected function setUp(): void
    {
        update_option('woocommerce_currency', 'NGN');
        update_option('woocommerce_ketapay_settings', [
            'enabled' => 'yes',
            'title' => 'Pay with KetaPay escrow',
            'description' => 'Pay safely.',
            'secret_key' => 'sk_test_fake',
            'webhook_secret' => self::WEBHOOK_SECRET,
            'seller_email' => 'shop@example.com',
            'category' => 'goods',
            'release_method' => 'buyer_confirms',
            'auto_release_hours' => '72',
            'send_images' => 'yes',
        ]);
        WC()->payment_gateways()->init();
        $this->api = new FakeKetaPay();
        $this->api->install();
    }

    protected function tearDown(): void
    {
        $this->api->uninstall();
        wc_clear_notices();
    }

    protected function product(string $price = '23500'): \WC_Product_Simple
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Ankara shirt');
        $product->set_regular_price($price);
        $product->save();

        return $product;
    }

    protected function order(int $quantity = 2, string $price = '23500'): \WC_Order
    {
        $order = wc_create_order();
        self::assertInstanceOf(\WC_Order::class, $order);
        $order->add_product($this->product($price), $quantity);
        $order->set_billing_email('buyer@example.com');
        $order->set_billing_phone('0801 234 5678');
        $order->set_payment_method(Plugin::GATEWAY_ID);
        $order->calculate_totals();
        $order->save();

        return $order;
    }

    protected function gateway(): \WC_Payment_Gateway
    {
        $gateway = WC()->payment_gateways()->payment_gateways()[Plugin::GATEWAY_ID] ?? null;
        self::assertInstanceOf(\WC_Payment_Gateway::class, $gateway);

        return $gateway;
    }

    protected function reload(\WC_Order $order): \WC_Order
    {
        $fresh = wc_get_order($order->get_id());
        self::assertInstanceOf(\WC_Order::class, $fresh);

        return $fresh;
    }

    /** @return list<string> the order's notes, oldest first */
    protected function notes(\WC_Order $order): array
    {
        $contents = [];
        foreach (wc_get_order_notes(['order_id' => $order->get_id(), 'order' => 'ASC']) as $note) {
            $contents[] = is_string($note->content ?? null) ? $note->content : '';
        }

        return $contents;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function session(\WC_Order $order, array $overrides = []): array
    {
        return $overrides + [
            'id' => 'cs_' . $order->get_id(),
            'object' => 'checkout_session',
            'status' => 'open',
            'url' => 'https://checkout.ketapay.com/cs_' . $order->get_id(),
            'amount' => (int) round((float) $order->get_total() * 100),
            'escrow_id' => null,
            'metadata' => ['wc_order_id' => (string) $order->get_id(), 'wc_site' => Plugin::siteId()],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function escrow(\WC_Order $order, array $overrides = []): array
    {
        return $overrides + [
            'id' => 'esc_' . $order->get_id(),
            'object' => 'escrow',
            'status' => 'active',
            'amount' => (int) round((float) $order->get_total() * 100),
            'checkout_session_id' => 'cs_' . $order->get_id(),
            'milestones' => [],
            'metadata' => ['wc_order_id' => (string) $order->get_id(), 'wc_site' => Plugin::siteId()],
        ];
    }

    /**
     * Posts a signed event to the webhook route, as KetaPay would.
     *
     * @param array<string, mixed> $object
     */
    protected function webhook(string $type, array $object, ?string $eventId = null, ?string $secret = null): \WP_REST_Response
    {
        $body = (string) json_encode([
            'id' => $eventId ?? wp_generate_uuid4(),
            'object' => 'event',
            'type' => $type,
            'api_version' => 'v1',
            'livemode' => false,
            'created_at' => gmdate('c'),
            'data' => ['object' => $object, 'milestone_id' => null],
        ]);
        $t = time();
        $signature = hash_hmac('sha256', "$t.$body", $secret ?? self::WEBHOOK_SECRET);

        $request = new \WP_REST_Request('POST', '/ketapay/v1/webhook');
        $request->set_header('KetaPay-Signature', "t=$t,v1=$signature");
        $request->set_header('Content-Type', 'application/json');
        $request->set_body($body);

        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }
}
