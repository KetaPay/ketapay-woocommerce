<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Unit\Support;

use KetaPay\WooCommerce\Checkout\OrderSnapshot;
use KetaPay\WooCommerce\Orders\OrderState;
use KetaPay\WooCommerce\Settings;

/** Builders with sensible defaults; each test overrides only what it is about. */
final class Fixtures
{
    /**
     * @param array<string, mixed> $options
     */
    public static function settings(array $options = []): Settings
    {
        return Settings::from($options + [
            'secret_key' => 'sk_test_51abc',
            'webhook_secret' => 'whsec_test',
            'seller_email' => 'shop@example.com',
        ]);
    }

    /**
     * @param list<array{name: string, quantity: int, total: string}>|null $items
     * @param list<string> $imageUrls
     */
    public static function order(
        string $total = '47000.00',
        ?array $items = null,
        ?string $phone = '0801 234 5678',
        string $currency = 'NGN',
        array $imageUrls = [],
        ?string $shippingMethod = 'Flat rate',
        string $storeName = 'Ada Fabrics',
    ): OrderSnapshot {
        return new OrderSnapshot(
            id: 1042,
            number: '1042',
            total: $total,
            currency: $currency,
            storeName: $storeName,
            billingEmail: 'buyer@example.com',
            billingPhone: $phone,
            items: $items ?? [
                ['name' => 'Ankara shirt (L)', 'quantity' => 2, 'total' => '30000.00'],
                ['name' => 'Head tie', 'quantity' => 1, 'total' => '15000.00'],
            ],
            shippingMethod: $shippingMethod,
            shippingTotal: '2000.00',
            imageUrls: $imageUrls,
            viewUrl: 'https://shop.example.com/my-account/view-order/1042/',
            returnUrl: 'https://shop.example.com/checkout/order-received/1042/?key=wc_order_abc',
            cancelUrl: 'https://shop.example.com/cart/?cancel_order=true&order=wc_order_abc',
        );
    }

    public static function state(
        string $status = 'pending',
        bool $isPaid = false,
        int $totalKobo = 4_700_000,
        ?string $session = 'cs_1',
        ?string $escrow = null,
    ): OrderState {
        return new OrderState($status, $isPaid, $totalKobo, $session, $escrow);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function escrow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'esc_1',
            'object' => 'escrow',
            'status' => 'active',
            'amount' => 4_700_000,
            'checkout_session_id' => 'cs_1',
            'milestones' => [['id' => 'ms_1', 'amount' => 4_700_000, 'status' => 'funded']],
            'metadata' => ['wc_order_id' => '1042', 'wc_site' => 'site-1'],
        ];
    }
}
