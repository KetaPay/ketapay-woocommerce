<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Unit\EventRules;

use KetaPay\WooCommerce\Orders\EventRules;
use KetaPay\WooCommerce\Tests\Unit\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class EndingsTest extends TestCase
{
    public function testReleasedMoneyCompletesTheOrder(): void
    {
        $update = EventRules::decide('escrow.completed', Fixtures::escrow(['status' => 'completed']), null, Fixtures::state(status: 'processing', isPaid: true, escrow: 'esc_1'));

        self::assertNull($update->markPaidWithEscrow);
        self::assertSame('completed', $update->status);
    }

    public function testCompletedBeforeFundedRecordsThePaymentFirst(): void
    {
        // Webhooks can arrive out of order; the order must never be Completed without a payment.
        $update = EventRules::decide('escrow.completed', Fixtures::escrow(['status' => 'completed']), null, Fixtures::state());

        self::assertSame('esc_1', $update->markPaidWithEscrow);
        self::assertSame('completed', $update->status);
    }

    public function testAlreadyCompletedIsLeftAlone(): void
    {
        $update = EventRules::decide('escrow.completed', Fixtures::escrow(), null, Fixtures::state(status: 'completed', isPaid: true, escrow: 'esc_1'));

        self::assertTrue($update->isEmpty());
    }

    public function testRefundRefundsTheOrder(): void
    {
        $update = EventRules::decide('escrow.refunded', Fixtures::escrow(['status' => 'refunded']), null, Fixtures::state(status: 'on-hold', isPaid: true, escrow: 'esc_1'));

        self::assertSame('refunded', $update->status);
        self::assertStringContainsString('refunded the buyer', $update->notes[0]);
    }

    public function testAlreadyRefundedIsLeftAlone(): void
    {
        self::assertTrue(EventRules::decide('escrow.refunded', Fixtures::escrow(), null, Fixtures::state(status: 'refunded', isPaid: true))->isEmpty());
    }

    public function testExpiredLinkCancelsAnUnpaidOrder(): void
    {
        $update = EventRules::decide('checkout_session.expired', ['object' => 'checkout_session', 'id' => 'cs_1'], null, Fixtures::state());

        self::assertSame('cancelled', $update->status);
    }

    public function testAnOlderExpiredLinkLeavesTheOrderAlone(): void
    {
        // The buyer came back and got a new link (cs_1); the first one (cs_0) expiring means nothing.
        $update = EventRules::decide('checkout_session.expired', ['object' => 'checkout_session', 'id' => 'cs_0'], null, Fixtures::state());

        self::assertTrue($update->isEmpty());
    }

    public function testExpiryNeverCancelsAPaidOrder(): void
    {
        $update = EventRules::decide('checkout_session.expired', ['object' => 'checkout_session', 'id' => 'cs_1'], null, Fixtures::state(status: 'processing', isPaid: true));

        self::assertTrue($update->isEmpty());
    }

    public function testExpiryLeavesAnOrderTheStoreOwnerMovedOnAlone(): void
    {
        $update = EventRules::decide('checkout_session.expired', ['object' => 'checkout_session', 'id' => 'cs_1'], null, Fixtures::state(status: 'on-hold'));

        self::assertTrue($update->isEmpty());
    }
}
