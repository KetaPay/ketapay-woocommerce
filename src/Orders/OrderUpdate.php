<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Orders;

/** What an event does to an order. OrderSync applies it; nothing here touches WooCommerce. */
final class OrderUpdate
{
    /**
     * @param list<string> $notes order notes, for the store owner only
     */
    public function __construct(
        /** Record the payment (WooCommerce's payment_complete) with this escrow as the transaction id */
        public readonly ?string $markPaidWithEscrow = null,
        /** New status without the "wc-" prefix, applied after the payment */
        public readonly ?string $status = null,
        public readonly array $notes = [],
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->markPaidWithEscrow === null && $this->status === null && $this->notes === [];
    }
}
