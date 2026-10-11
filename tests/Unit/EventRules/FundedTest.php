<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Unit\EventRules;

use KetaPay\WooCommerce\Orders\EventRules;
use KetaPay\WooCommerce\Tests\Unit\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class FundedTest extends TestCase
{
    public function testMarksTheOrderPaidWithTheEscrowAsTransaction(): void
    {
        $update = EventRules::decide('escrow.funded', Fixtures::escrow(), null, Fixtures::state());

        self::assertSame('esc_1', $update->markPaidWithEscrow);
        self::assertNull($update->status); // WooCommerce's payment_complete picks Processing (or Completed for downloads)
        self::assertStringContainsString('Paid ₦47,000.00 into KetaPay escrow (esc_1)', $update->notes[0]);
    }

    public function testTheSamePaymentTwiceChangesNothing(): void
    {
        $update = EventRules::decide('escrow.funded', Fixtures::escrow(), null, Fixtures::state(status: 'processing', isPaid: true, escrow: 'esc_1'));

        self::assertTrue($update->isEmpty());
    }

    public function testASecondPaymentPutsTheOrderOnHold(): void
    {
        $paid = Fixtures::state(status: 'processing', isPaid: true, escrow: 'esc_1');

        $update = EventRules::decide('escrow.funded', Fixtures::escrow(['id' => 'esc_2', 'checkout_session_id' => 'cs_old']), null, $paid);

        self::assertNull($update->markPaidWithEscrow);
        self::assertSame('on-hold', $update->status);
        self::assertStringContainsString('second KetaPay payment', $update->notes[0]);
        self::assertStringContainsString('esc_2', $update->notes[0]);
        self::assertStringContainsString('esc_1', $update->notes[0]);
    }

    public function testAnAmountThatDoesNotMatchTheOrderIsHeldForReview(): void
    {
        $update = EventRules::decide('escrow.funded', Fixtures::escrow(['amount' => 4_500_000]), null, Fixtures::state());

        self::assertSame('esc_1', $update->markPaidWithEscrow); // the money is in escrow: record it
        self::assertSame('on-hold', $update->status);
        self::assertStringContainsString('₦45,000.00', $update->notes[0]);
        self::assertStringContainsString('₦47,000.00', $update->notes[0]);
    }

    public function testAPaymentForACancelledOrderIsStillRecorded(): void
    {
        // The checkout link expired and the order was cancelled, then a bank transfer landed.
        $update = EventRules::decide('escrow.funded', Fixtures::escrow(), null, Fixtures::state(status: 'cancelled'));

        self::assertSame('esc_1', $update->markPaidWithEscrow);
    }
}
