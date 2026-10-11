<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Unit;

use KetaPay\WooCommerce\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaultsForAFreshInstall(): void
    {
        $settings = Settings::from([]);

        self::assertSame('goods', $settings->category);
        self::assertSame('buyer_confirms', $settings->releaseMethod);
        self::assertSame(72, $settings->autoReleaseHours);
        self::assertTrue($settings->sendImages);
        self::assertFalse($settings->debug);
        self::assertSame([], $settings->webhookSecrets);
    }

    public function testListsWhatStopsCheckoutInPlainWords(): void
    {
        $problems = Settings::from(['secret_key' => 'pk_test_123', 'seller_email' => 'not an email'])->problems();

        self::assertCount(3, $problems);
        self::assertStringContainsString('secret key', $problems[0]);
        self::assertStringContainsString('email', $problems[1]);
        self::assertStringContainsString('webhook', $problems[2]);
    }

    public function testReadyWhenKeyEmailAndWebhookSecretAreSet(): void
    {
        $settings = Settings::from(['secret_key' => 'sk_live_abc', 'seller_email' => 'shop@example.com', 'webhook_secret' => 'whsec_1']);

        self::assertSame([], $settings->problems());
        self::assertFalse($settings->isTestMode());
    }

    public function testWpConfigConstantsWinOverTheSettingsPage(): void
    {
        $settings = Settings::from(
            ['secret_key' => 'sk_test_from_db', 'webhook_secret' => 'whsec_db'],
            ['KETAPAY_SECRET_KEY' => 'sk_live_from_config', 'KETAPAY_WEBHOOK_SECRET' => 'whsec_config'],
        );

        self::assertSame('sk_live_from_config', $settings->secretKey);
        self::assertSame(['whsec_config'], $settings->webhookSecrets);
    }

    public function testTwoWebhookSecretsDuringRotation(): void
    {
        self::assertSame(['whsec_new', 'whsec_old'], Settings::from(['webhook_secret' => ' whsec_new , whsec_old '])->webhookSecrets);
    }

    public function testUnknownChoicesFallBackToSafeDefaults(): void
    {
        $settings = Settings::from(['category' => 'cars', 'release_method' => 'instant', 'auto_release_hours' => '5']);

        self::assertSame('goods', $settings->category);
        self::assertSame('buyer_confirms', $settings->releaseMethod);
        self::assertNull($settings->autoReleaseHours); // never release without the buyer, rather than guess
    }

    public function testNeverAutoRelease(): void
    {
        self::assertNull(Settings::from(['auto_release_hours' => ''])->autoReleaseHours);
    }

    public function testSellerPhoneIsNormalisedOrDropped(): void
    {
        self::assertSame('+2348031234567', Settings::from(['seller_phone' => '0803 123 4567'])->sellerPhone);
        self::assertNull(Settings::from(['seller_phone' => '12345'])->sellerPhone);
    }

    public function testNonScalarOptionsAreIgnored(): void
    {
        $settings = Settings::from(['secret_key' => ['sk_test_x'], 'debug' => ['yes']]);

        self::assertSame('', $settings->secretKey);
        self::assertFalse($settings->debug);
    }
}
