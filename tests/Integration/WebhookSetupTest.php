<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Integration;

use KetaPay\WooCommerce\Plugin;
use KetaPay\WooCommerce\Tests\Integration\Support\IntegrationTestCase;
use KetaPay\WooCommerce\Webhooks\WebhookSetup;

final class WebhookSetupTest extends IntegrationTestCase
{
    public function testRegistersTheStoresWebhookAndReturnsItsSecret(): void
    {
        $this->api
            ->on('GET', '/webhook_endpoints', 200, ['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null])
            ->on('POST', '/webhook_endpoints', 200, ['id' => 'we_1', 'object' => 'webhook_endpoint', 'url' => 'https://shop.example.com/wp-json/ketapay/v1/webhook', 'secret' => 'whsec_new']);

        $secret = WebhookSetup::connect(Plugin::client(), 'https://shop.example.com/wp-json/ketapay/v1/webhook', 'Ada Fabrics');

        self::assertSame('whsec_new', $secret);
        self::assertSame(['url' => 'https://shop.example.com/wp-json/ketapay/v1/webhook', 'enabled_events' => ['*'], 'description' => 'WooCommerce: Ada Fabrics'], $this->api->body(1));
    }

    public function testRotatesTheSecretWhenTheUrlIsAlreadyRegistered(): void
    {
        $url = 'https://shop.example.com/wp-json/ketapay/v1/webhook';
        $this->api
            ->on('GET', '/webhook_endpoints', 200, ['object' => 'list', 'data' => [
                ['id' => 'we_other', 'object' => 'webhook_endpoint', 'url' => 'https://elsewhere.example.com/hook'],
                ['id' => 'we_1', 'object' => 'webhook_endpoint', 'url' => $url],
            ], 'has_more' => false, 'next_cursor' => null])
            ->on('POST', '/webhook_endpoints/we_1/rotate_secret', 200, ['object' => 'webhook_secret', 'secret' => 'whsec_rotated', 'previous_secret_expires_at' => gmdate('c')]);

        self::assertSame('whsec_rotated', WebhookSetup::connect(Plugin::client(), $url, 'Ada Fabrics'));
        self::assertNotContains('POST /webhook_endpoints', $this->api->calls(), 'never a second endpoint: every event would arrive twice');
    }

    public function testTheWebhookUrlIsTheRestRoute(): void
    {
        self::assertStringEndsWith('/ketapay/v1/webhook', Plugin::webhookUrl());
    }
}
