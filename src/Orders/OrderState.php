<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Orders;

/** The parts of a WooCommerce order the event rules look at. */
final class OrderState
{
    public function __construct(
        /** WooCommerce status without the "wc-" prefix: pending, processing, on-hold, completed, cancelled, refunded, failed */
        public readonly string $status,
        public readonly bool $isPaid,
        public readonly int $totalKobo,
        public readonly ?string $checkoutSessionId,
        public readonly ?string $escrowId,
    ) {}
}
