<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce;

use KetaPay\WooCommerce\Checkout\Phone;

/**
 * The gateway's settings, read once and checked. Keys can come from wp-config.php constants
 * (KETAPAY_SECRET_KEY, KETAPAY_WEBHOOK_SECRET), which win over the settings page, so they never
 * have to sit in the database.
 */
final class Settings
{
    public const CATEGORIES = ['goods', 'services', 'property', 'vehicle', 'digital', 'other'];
    public const RELEASE_METHODS = ['buyer_confirms', 'release_code'];
    /** Choices offered for auto release; '' means never (the buyer must release). */
    public const AUTO_RELEASE_CHOICES = ['', '24', '48', '72', '168', '336', '720'];

    /**
     * @param list<string> $webhookSecrets newest first; two while a secret is being rotated
     * @param 'goods'|'services'|'property'|'vehicle'|'digital'|'other' $category
     * @param 'buyer_confirms'|'release_code' $releaseMethod
     */
    public function __construct(
        public readonly string $secretKey,
        public readonly array $webhookSecrets,
        public readonly string $sellerEmail,
        public readonly ?string $sellerPhone,
        public readonly string $category,
        public readonly string $releaseMethod,
        public readonly ?int $autoReleaseHours,
        public readonly bool $sendImages,
        public readonly bool $debug,
    ) {}

    /**
     * @param array<string, mixed> $options the gateway's saved settings
     * @param array<string, string> $constants KETAPAY_* constants defined in wp-config.php
     */
    public static function from(array $options, array $constants = []): self
    {
        $secretKey = trim($constants['KETAPAY_SECRET_KEY'] ?? self::string($options, 'secret_key'));
        $webhookSecret = trim($constants['KETAPAY_WEBHOOK_SECRET'] ?? self::string($options, 'webhook_secret'));
        // A comma-separated pair keeps the old secret working while it is rotated.
        $webhookSecrets = array_values(array_filter(array_map('trim', explode(',', $webhookSecret))));

        $category = self::string($options, 'category');
        $releaseMethod = self::string($options, 'release_method');
        $autoRelease = self::string($options, 'auto_release_hours', '72');

        return new self(
            secretKey: $secretKey,
            webhookSecrets: $webhookSecrets,
            sellerEmail: trim(self::string($options, 'seller_email')),
            sellerPhone: Phone::normalise(self::string($options, 'seller_phone')),
            category: in_array($category, self::CATEGORIES, true) ? $category : 'goods',
            releaseMethod: in_array($releaseMethod, self::RELEASE_METHODS, true) ? $releaseMethod : 'buyer_confirms',
            autoReleaseHours: in_array($autoRelease, self::AUTO_RELEASE_CHOICES, true) && $autoRelease !== ''
                ? (int) $autoRelease
                : null,
            sendImages: self::string($options, 'send_images', 'yes') === 'yes',
            debug: self::string($options, 'debug', 'no') === 'yes',
        );
    }

    /**
     * What stops the gateway taking payments, in words for the store owner. Empty when ready.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if (!preg_match('/^sk_(test|live)_/', $this->secretKey)) {
            $problems[] = 'Add your KetaPay secret key (it starts with sk_test_ or sk_live_).';
        }
        if (filter_var($this->sellerEmail, FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'Add the email address of the KetaPay account that receives the money.';
        }
        if ($this->webhookSecrets === []) {
            $problems[] = 'Add the webhook signing secret, so orders update when buyers pay.';
        }

        return $problems;
    }

    public function isTestMode(): bool
    {
        return str_starts_with($this->secretKey, 'sk_test_');
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function string(array $options, string $key, string $default = ''): string
    {
        $value = $options[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }
}
