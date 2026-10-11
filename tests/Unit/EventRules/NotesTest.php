<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Unit\EventRules;

use KetaPay\WooCommerce\Orders\EventRules;
use KetaPay\WooCommerce\Tests\Unit\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class NotesTest extends TestCase
{
    public function testDeliveryAddsANote(): void
    {
        $update = EventRules::decide('milestone.delivered', Fixtures::escrow(), 'ms_1', Fixtures::state(status: 'processing', isPaid: true));

        self::assertNull($update->status);
        self::assertStringContainsString('Marked as delivered', $update->notes[0]);
    }

    public function testReleaseNamesTheAmount(): void
    {
        $update = EventRules::decide('milestone.released', Fixtures::escrow(), 'ms_1', Fixtures::state(status: 'processing', isPaid: true));

        self::assertSame(['The buyer released ₦47,000.00 to you.'], $update->notes);
    }

    public function testReleaseOfAnUnknownMilestoneStillAddsANote(): void
    {
        $update = EventRules::decide('milestone.released', Fixtures::escrow(), 'ms_other', Fixtures::state());

        self::assertSame(['The buyer released a payment to you.'], $update->notes);
    }

    public function testPayoutsAndRefundsAddNotes(): void
    {
        $state = Fixtures::state(status: 'completed', isPaid: true);

        self::assertSame(
            ['KetaPay sent ₦46,295.00 to your bank account ending 4821.'],
            EventRules::decide('payout.sent', ['object' => 'payout', 'amount' => 4_629_500, 'account_number_last4' => '4821'], null, $state)->notes,
        );
        self::assertStringContainsString(
            'account closed',
            EventRules::decide('payout.failed', ['object' => 'payout', 'amount' => 100, 'failure_reason' => 'account closed'], null, $state)->notes[0],
        );
        self::assertStringContainsString(
            'no reason given',
            EventRules::decide('refund.failed', ['object' => 'refund', 'amount' => 100], null, $state)->notes[0],
        );
    }

    public function testChargebacksAddNotes(): void
    {
        $state = Fixtures::state(status: 'completed', isPaid: true);

        self::assertStringContainsString('(fraudulent)', EventRules::decide('chargeback.opened', ['object' => 'chargeback', 'amount' => 100, 'reason' => 'fraudulent'], null, $state)->notes[0]);
        self::assertSame(['Chargeback closed: won.'], EventRules::decide('chargeback.closed', ['object' => 'chargeback', 'status' => 'won'], null, $state)->notes);
    }

    public function testUnknownEventTypesAreIgnored(): void
    {
        // The API adds event types over time; an old plugin must not break on them.
        self::assertTrue(EventRules::decide('escrow.something_new', Fixtures::escrow(), null, Fixtures::state())->isEmpty());
        self::assertTrue(EventRules::decide('dispute.evidence_requested', [], null, Fixtures::state())->isEmpty());
    }
}
