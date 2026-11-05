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

        self::assertSame('buyer_confirms', $settings->releaseMethod);
        self::assertSame(72, $settings->inspectionHours);
        self::assertTrue($settings->sendImages);
        self::assertFalse($settings->debug);
        self::assertSame([], $settings->webhookSecrets);
    }

    public function testListsWhatStopsCheckoutInPlainWords(): void
    {
        $problems = Settings::from(['secret_key' => 'pk_test_123'])->problems();

        self::assertCount(2, $problems);
        self::assertStringContainsString('secret key', $problems[0]);
        self::assertStringContainsString('webhook', $problems[1]);
    }

    public function testReadyWhenKeyAndWebhookSecretAreSet(): void
    {
        $settings = Settings::from(['secret_key' => 'sk_live_abc', 'webhook_secret' => 'whsec_1']);

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
        $settings = Settings::from(['release_method' => 'instant', 'inspection_hours' => '5']);

        self::assertSame('buyer_confirms', $settings->releaseMethod);
        self::assertSame(72, $settings->inspectionHours); // the API's own default, rather than guess
    }

    public function testStoresSavedBeforeTheInspectionPeriodGetTheDefault(): void
    {
        // Old installs saved auto_release_hours ('' meant never); the API no longer has it
        self::assertSame(72, Settings::from(['auto_release_hours' => ''])->inspectionHours);
    }

    public function testNonScalarOptionsAreIgnored(): void
    {
        $settings = Settings::from(['secret_key' => ['sk_test_x'], 'debug' => ['yes']]);

        self::assertSame('', $settings->secretKey);
        self::assertFalse($settings->debug);
    }
}
