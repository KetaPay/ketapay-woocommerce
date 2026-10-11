<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Unit\EventRules;

use KetaPay\WooCommerce\Orders\EventRules;
use KetaPay\WooCommerce\Tests\Unit\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class DisputesTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function dispute(array $overrides = []): array
    {
        return $overrides + [
            'object' => 'dispute',
            'id' => 'dsp_1',
            'escrow_id' => 'esc_1',
            'opened_by' => 'buyer',
            'reason' => 'not_as_agreed',
            'outcome' => null,
            'url' => 'https://app.ketapay.com/disputes/dsp_1',
        ];
    }

    public function testAProblemReportPutsTheOrderOnHold(): void
    {
        $update = EventRules::decide('dispute.opened', self::dispute(), null, Fixtures::state(status: 'processing', isPaid: true, escrow: 'esc_1'));

        self::assertSame('on-hold', $update->status);
        self::assertSame(
            'Buyer reported a problem (not as agreed). KetaPay is handling it and the money stays in escrow until it is settled: https://app.ketapay.com/disputes/dsp_1',
            $update->notes[0],
        );
    }

    public function testASellerReportIsLabelledAsTheSeller(): void
    {
        $update = EventRules::decide('dispute.opened', self::dispute(['opened_by' => 'seller', 'reason' => 'buyer_unresponsive']), null, Fixtures::state(status: 'processing', isPaid: true));

        self::assertStringStartsWith('Seller reported a problem (buyer unresponsive)', $update->notes[0]);
    }

    public function testAProblemReportNeverReopensAFinishedOrder(): void
    {
        $update = EventRules::decide('dispute.opened', self::dispute(), null, Fixtures::state(status: 'completed', isPaid: true));

        self::assertNull($update->status);
        self::assertCount(1, $update->notes);
    }

    public function testSettlingPutsAHeldOrderBackToProcessing(): void
    {
        $update = EventRules::decide('dispute.resolved', self::dispute(['outcome' => 'partially_refunded']), null, Fixtures::state(status: 'on-hold', isPaid: true));

        self::assertSame('processing', $update->status);
        self::assertSame(['The problem report was settled: partially refunded.'], $update->notes);
    }

    public function testSettlingAfterTheFinalStatusOnlyAddsANote(): void
    {
        $update = EventRules::decide('dispute.resolved', self::dispute(['outcome' => 'refunded']), null, Fixtures::state(status: 'refunded', isPaid: true));

        self::assertNull($update->status);
    }
}
