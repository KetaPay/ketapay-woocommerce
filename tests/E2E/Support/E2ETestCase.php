<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\E2E\Support;

use KetaPay\WooCommerce\Orders\OrderSync;
use KetaPay\WooCommerce\Plugin;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Base for E2E tests: the plugin and its locked SDK in a real WooCommerce, against the staging API.
 *
 * Staging can't reach a store on a CI runner, so webhooks are the one part not delivered for real: a test
 * reads the real event from the API, signs it and posts it to the store's webhook route.
 */
abstract class E2ETestCase extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_e2e';

    private static int $counter = 0;

    protected StagingApi $api;

    private ?\Closure $clientConfig = null;

    protected function setUp(): void
    {
        $baseUrl = getenv('KETAPAY_E2E_BASE_URL');
        $key = getenv('KETAPAY_E2E_SECRET_KEY');
        if (!is_string($baseUrl) || $baseUrl === '' || !is_string($key) || $key === '') {
            self::markTestSkipped('E2E not configured: set KETAPAY_E2E_BASE_URL and KETAPAY_E2E_SECRET_KEY');
        }
        $this->api = new StagingApi($baseUrl, $key);

        update_option('woocommerce_currency', 'NGN');
        update_option('woocommerce_ketapay_settings', [
            'enabled' => 'yes',
            'title' => 'Pay with KetaPay escrow',
            'description' => 'Pay safely.',
            'secret_key' => $key,
            'webhook_secret' => self::WEBHOOK_SECRET,
            'release_method' => 'buyer_confirms',
            'inspection_hours' => '72',
            'send_images' => 'yes',
        ]);
        WC()->payment_gateways()->init();
        $this->clientConfig = static function (array $config) use ($baseUrl): array {
            $config['baseUrl'] = $baseUrl;
            $config['maxRetries'] = 1;

            return $config;
        };
        add_filter('ketapay_wc_client_config', $this->clientConfig);
    }

    protected function tearDown(): void
    {
        if ($this->clientConfig !== null) {
            remove_filter('ketapay_wc_client_config', $this->clientConfig);
        }
        wc_clear_notices();
    }

    /** Stamped on everything the run creates, so staging can be cleaned up by run. */
    protected static function runId(): string
    {
        $run = getenv('KETAPAY_E2E_RUN_ID');

        return is_string($run) && $run !== '' ? $run : 'e2e-woo-' . base_convert((string) time(), 10, 36);
    }

    /** A new order with a fresh buyer, ready to pay. */
    protected function order(string $price = '3000'): \WC_Order
    {
        $order = wc_create_order();
        self::assertInstanceOf(\WC_Order::class, $order);
        $order->add_product($this->product($price));
        $order->set_billing_email('buyer+' . self::runId() . '-' . ++self::$counter . '@e2e.ketapay.test');
        $order->set_payment_method(Plugin::GATEWAY_ID);
        $order->calculate_totals();
        $order->save();

        return $order;
    }

    protected function product(string $price): \WC_Product_Simple
    {
        $product = new \WC_Product_Simple();
        $product->set_name('E2E Ankara shirt');
        $product->set_regular_price($price);
        $product->save();

        return $product;
    }

    /** @return array{result: string, redirect?: string} what WooCommerce does with the buyer */
    protected function placeOrder(\WC_Order $order): array
    {
        $gateway = WC()->payment_gateways()->payment_gateways()[Plugin::GATEWAY_ID] ?? null;
        self::assertInstanceOf(\WC_Payment_Gateway::class, $gateway);
        /** @var array{result: string, redirect?: string} $result */
        $result = $gateway->process_payment($order->get_id());

        return $result;
    }

    protected function sessionId(\WC_Order $order): string
    {
        $id = $this->reload($order)->get_meta(OrderSync::META_SESSION);
        self::assertIsString($id);
        self::assertNotSame('', $id);

        return $id;
    }

    /** Places the order and pays its checkout with the test helper. Returns the escrow's id. */
    protected function pay(\WC_Order $order): string
    {
        self::assertSame('success', $this->placeOrder($order)['result']);
        $paid = $this->api->post('/test_helpers/checkout_sessions/' . $this->sessionId($order) . '/pay');

        return StagingApi::text($paid, 'escrow_id');
    }

    /**
     * Waits for the API's real event about this object and returns it as it was sent.
     *
     * @return array<string, mixed>
     */
    protected function realEvent(string $type, string $objectId): array
    {
        $found = null;
        $this->eventually(function () use ($type, $objectId, &$found): void {
            foreach (StagingApi::rows($this->api->get("/events?type=$type&limit=100"), 'data') as $event) {
                $object = is_array($event['data'] ?? null) && is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
                if (($object['id'] ?? null) === $objectId || ($object['escrow_id'] ?? null) === $objectId) {
                    $found = $event;

                    return;
                }
            }
            self::fail("No $type event for $objectId");
        });
        self::assertIsArray($found);

        return $found;
    }

    /**
     * Posts an event to the store's webhook route, signed with the store's secret.
     *
     * @param array<string, mixed> $event
     */
    protected function deliver(array $event): void
    {
        $body = (string) json_encode($event, JSON_UNESCAPED_SLASHES);
        $t = time();
        $request = new \WP_REST_Request('POST', '/ketapay/v1/webhook');
        $request->set_header('KetaPay-Signature', "t=$t,v1=" . hash_hmac('sha256', "$t.$body", self::WEBHOOK_SECRET));
        $request->set_header('Content-Type', 'application/json');
        $request->set_body($body);
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame(200, $response->get_status(), (string) json_encode($response->get_data()));
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

    /** Retries an assertion, for effects that land a moment after the API call returns. */
    protected function eventually(callable $check, float $timeoutSeconds = 20): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            try {
                $check();

                return;
            } catch (AssertionFailedError $e) {
                if (microtime(true) >= $deadline) {
                    throw $e;
                }
                usleep(500_000);
            }
        }
    }
}
