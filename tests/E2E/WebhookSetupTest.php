<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\E2E;

use KetaPay\WooCommerce\Plugin;
use KetaPay\WooCommerce\Tests\E2E\Support\E2ETestCase;
use KetaPay\WooCommerce\Tests\E2E\Support\StagingApi;
use KetaPay\WooCommerce\Webhooks\WebhookSetup;
use PHPUnit\Framework\Attributes\TestDox;

final class WebhookSetupTest extends E2ETestCase
{
    private string $url = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->url = 'https://example.com/ketapay-e2e/' . self::runId();
    }

    protected function tearDown(): void
    {
        // Left behind, the endpoint would have staging retrying every event against example.com.
        foreach ($this->endpoints() as $endpoint) {
            $this->api->delete('/webhook_endpoints/' . StagingApi::text($endpoint, 'id'));
        }
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> this run's endpoints */
    private function endpoints(): array
    {
        return array_values(array_filter(
            StagingApi::rows($this->api->get('/webhook_endpoints?limit=100'), 'data'),
            fn(array $endpoint): bool => ($endpoint['url'] ?? null) === $this->url,
        ));
    }

    #[TestDox('[WE2E-011] connecting registers one endpoint for all events and returns its secret; connecting again rotates it without adding a second')]
    public function testConnect(): void
    {
        $first = WebhookSetup::connect(Plugin::client(), $this->url, 'E2E store');

        self::assertStringStartsWith('whsec_', $first);
        $endpoints = $this->endpoints();
        self::assertCount(1, $endpoints);
        self::assertSame(['*'], $endpoints[0]['enabled_events']);

        $second = WebhookSetup::connect(Plugin::client(), $this->url, 'E2E store');

        self::assertStringStartsWith('whsec_', $second);
        self::assertNotSame($first, $second);
        self::assertCount(1, $this->endpoints());
    }
}
