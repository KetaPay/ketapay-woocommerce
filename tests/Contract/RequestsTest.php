<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Contract;

use KetaPay\KetaPay;
use KetaPay\WooCommerce\Checkout\SessionParams;
use KetaPay\WooCommerce\Orders\EventRules;
use KetaPay\WooCommerce\Tests\Unit\Support\Fixtures;
use KetaPay\WooCommerce\Webhooks\WebhookSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every request the plugin makes, sent through the real SDK to a Prism mock of the API spec with
 * request validation on: a body the spec doesn't allow fails here. Run with `composer test:contract`.
 * This checks the plugin against the spec, not against the live API (there is no staging API yet).
 */
final class RequestsTest extends TestCase
{
    private KetaPay $client;

    protected function setUp(): void
    {
        $url = getenv('KETAPAY_MOCK_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('No Prism mock: run `composer test:contract`');
        }
        $this->client = new KetaPay(['apiKey' => 'sk_test_contract', 'baseUrl' => $url, 'maxRetries' => 0]);
    }

    /** @return iterable<string, array{array<string, mixed>, \KetaPay\WooCommerce\Checkout\OrderSnapshot}> */
    public static function sessions(): iterable
    {
        yield 'defaults' => [[], Fixtures::order()];
        yield 'release code, seller phone' => [['release_method' => 'release_code', 'seller_phone' => '08031234567'], Fixtures::order()];
        yield 'never auto release' => [['auto_release_hours' => ''], Fixtures::order()];
        yield 'longest auto release, services' => [['auto_release_hours' => '720', 'category' => 'services'], Fixtures::order()];
        yield 'every category' => [['category' => 'property'], Fixtures::order()];
        yield 'product photos' => [[], Fixtures::order(imageUrls: array_map(static fn(int $i): string => "https://cdn.example.com/$i.jpg", range(1, 12)))];
        yield 'buyer without a usable phone' => [[], Fixtures::order(phone: '+44 7700 900123')];
        yield 'no line items' => [[], Fixtures::order(total: '1', items: [], shippingMethod: null, storeName: 'Y')];
        yield 'text at the API limits' => [[], Fixtures::order(
            items: array_fill(0, 200, ['name' => str_repeat('Very long product name ', 3), 'quantity' => 1, 'total' => '100']),
            storeName: str_repeat('Big Store ', 20),
        )];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('sessions')]
    public function testTheSpecAcceptsEveryCheckoutSessionThePluginBuilds(array $options, \KetaPay\WooCommerce\Checkout\OrderSnapshot $order): void
    {
        $session = $this->client->checkoutSessions->create(SessionParams::build($order, Fixtures::settings($options), 'site-1'));

        // The fields process_payment reads from the response.
        self::assertNotSame('', $session->id);
        self::assertStringStartsWith('https://', $session->url);
        self::assertContains($session->status, ['open', 'complete', 'expired', 'cancelled']);
        self::assertIsInt($session->amount);
    }

    public function testReusingAndCancellingALink(): void
    {
        $id = '2c9a7f4e-1b3d-4e6a-8f2c-5d7b9e1a3c60';

        $session = $this->client->checkoutSessions->retrieve($id);
        self::assertTrue($session->escrow_id === null || is_string($session->escrow_id));

        $this->client->checkoutSessions->cancel($id);
        $this->addToAssertionCount(1); // accepted by the spec
    }

    public function testAnEscrowFromTheApiIsReadAsAPayment(): void
    {
        $escrow = $this->client->escrows->retrieve('3b1f6c2e-8d4a-4e7b-9c1d-2a5f8e6b7c90')->toArray();

        $update = EventRules::decide('escrow.funded', $escrow, null, Fixtures::state(totalKobo: is_int($escrow['amount']) ? $escrow['amount'] : 0));

        self::assertSame($escrow['id'], $update->markPaidWithEscrow);
        self::assertNull($update->status, 'amounts match, so nothing is held for review');
    }

    public function testWebhookSetupRegistersOrRotates(): void
    {
        $secret = WebhookSetup::connect($this->client, 'https://shop.example.com/wp-json/ketapay/v1/webhook', 'Ada Fabrics');

        self::assertNotSame('', $secret);
    }
}
