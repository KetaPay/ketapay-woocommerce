<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Checkout;

/**
 * What a checkout session needs from a WooCommerce order, as plain values. Built from a WC_Order by
 * OrderSnapshotFactory; kept separate so the session rules can be tested without WooCommerce.
 */
final class OrderSnapshot
{
    /**
     * @param list<array{name: string, quantity: int, total: string}> $items line items, totals as decimal strings
     * @param list<string> $imageUrls product images, in line item order
     */
    public function __construct(
        public readonly int $id,
        public readonly string $number,
        public readonly string $total,
        public readonly string $currency,
        public readonly string $storeName,
        public readonly string $billingEmail,
        public readonly ?string $billingPhone,
        public readonly array $items,
        public readonly ?string $shippingMethod,
        public readonly string $shippingTotal,
        public readonly array $imageUrls,
        public readonly string $viewUrl,
        public readonly string $returnUrl,
        public readonly string $cancelUrl,
    ) {}
}
