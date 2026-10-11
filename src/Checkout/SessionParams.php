<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Checkout;

use KetaPay\WooCommerce\Settings;

/**
 * Builds the `POST /checkout_sessions` body for an order. Typed with the SDK's own parameter shape,
 * so PHPStan checks it against the API spec.
 *
 * @phpstan-import-type CreateCheckoutSessionRequest from \KetaPay\Types\Params
 */
final class SessionParams
{
    private const TITLE_MAX = 120;
    private const DESCRIPTION_MIN = 20;
    private const DESCRIPTION_MAX = 2000;
    private const IMAGES_MAX = 10;

    /**
     * Metadata keys on every session. KetaPay copies them onto the escrow, so webhooks lead back to
     * the order, and `site` stops a store acting on another store's events when two stores share
     * one KetaPay account.
     */
    public const META_ORDER_ID = 'wc_order_id';
    public const META_SITE = 'wc_site';

    /**
     * @return CreateCheckoutSessionRequest
     */
    public static function build(OrderSnapshot $order, Settings $settings, string $siteId): array
    {
        if ($order->currency !== 'NGN') {
            throw new \InvalidArgumentException("KetaPay only takes naira; this order is in {$order->currency}");
        }

        $buyer = ['email' => $order->billingEmail];
        $phone = Phone::normalise($order->billingPhone);
        if ($phone !== null) {
            $buyer['phone'] = $phone;
        }

        $seller = ['email' => $settings->sellerEmail];
        if ($settings->sellerPhone !== null) {
            $seller['phone'] = $settings->sellerPhone;
        }

        $release = ['method' => $settings->releaseMethod];
        if ($settings->releaseMethod === 'buyer_confirms') {
            $release['auto_release_hours'] = $settings->autoReleaseHours;
        }

        $params = [
            'amount' => Money::toKobo($order->total),
            'currency' => 'NGN',
            'category' => $settings->category,
            'title' => self::truncate("Order #{$order->number} from {$order->storeName}", self::TITLE_MAX),
            'description' => self::description($order),
            'url' => $order->viewUrl,
            'buyer' => $buyer,
            'seller' => $seller,
            'release' => $release,
            'return_url' => $order->returnUrl,
            'cancel_url' => $order->cancelUrl,
            'external_reference' => (string) $order->id,
            'metadata' => [
                self::META_ORDER_ID => (string) $order->id,
                self::META_SITE => $siteId,
            ],
        ];

        $images = self::images($order->imageUrls);
        if ($settings->sendImages && $images !== []) {
            $params['images'] = $images;
        }

        return $params;
    }

    /**
     * The line items, as both sides will see them in the escrow and as KetaPay reads them in a
     * dispute: "2 × Ankara shirt (L): ₦30,000.00".
     */
    private static function description(OrderSnapshot $order): string
    {
        $lines = [];
        foreach ($order->items as $item) {
            $lines[] = "{$item['quantity']} × {$item['name']}: " . Money::format(Money::toKobo($item['total']));
        }
        if ($order->shippingMethod !== null && $order->shippingMethod !== '') {
            $lines[] = "Delivery ({$order->shippingMethod}): " . Money::format(Money::toKobo($order->shippingTotal));
        }
        $lines[] = 'Total: ' . Money::format(Money::toKobo($order->total));
        $text = implode("\n", $lines);
        if (mb_strlen($text) < self::DESCRIPTION_MIN) {
            $text = trim("Order #{$order->number} from {$order->storeName}\n$text");
        }
        if (mb_strlen($text) < self::DESCRIPTION_MIN) {
            $text .= str_repeat('.', self::DESCRIPTION_MIN - mb_strlen($text));
        }

        return self::truncate($text, self::DESCRIPTION_MAX);
    }

    /**
     * KetaPay copies images from a public HTTPS link, so anything else (a store on localhost, an
     * http:// site) is left out instead of failing the checkout.
     *
     * @param list<string> $urls
     * @return list<array{url: string}>
     */
    private static function images(array $urls): array
    {
        $images = [];
        foreach (array_unique($urls) as $url) {
            if (str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $images[] = ['url' => $url];
            }
        }

        return array_slice($images, 0, self::IMAGES_MAX);
    }

    private static function truncate(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
