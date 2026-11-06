<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\E2E;

use KetaPay\WooCommerce\Tests\E2E\Support\E2ETestCase;
use KetaPay\WooCommerce\Tests\E2E\Support\StagingApi;
use PHPUnit\Framework\Attributes\TestDox;

/** How a paid order ends: each test takes a funded order to one real outcome on the API. */
final class OutcomesTest extends E2ETestCase
{
    private function paidOrder(): \WC_Order
    {
        $order = $this->order();
        $this->deliver($this->realEvent('escrow.funded', $this->pay($order)));
        self::assertSame('processing', $this->reload($order)->get_status());

        return $order;
    }

    #[TestDox('[WE2E-005] after the store delivers and the buyer releases, the real escrow.completed event moves the order to Completed')]
    public function testRelease(): void
    {
        $order = $this->paidOrder();
        $escrowId = $this->reload($order)->get_transaction_id();
        $token = $this->api->tokenFor($order->get_billing_email());

        foreach (StagingApi::rows($this->api->get("/escrows/$escrowId"), 'milestones') as $milestone) {
            $path = "/escrows/$escrowId/milestones/" . StagingApi::text($milestone, 'id');
            $this->api->post("$path/deliver", ['note' => 'Shipped']);
            $this->api->post("$path/release", [], $token);
        }

        $this->deliver($this->realEvent('escrow.completed', $escrowId));
        self::assertSame('completed', $this->reload($order)->get_status());
    }

    #[TestDox("[WE2E-006] a real refund's escrow.refunded event moves the order to Refunded")]
    public function testRefund(): void
    {
        $order = $this->paidOrder();
        $escrowId = $this->reload($order)->get_transaction_id();

        $this->api->post("/escrows/$escrowId/refund", ['reason' => 'Out of stock, agreed with the customer.']);

        $this->deliver($this->realEvent('escrow.refunded', $escrowId));
        self::assertSame('refunded', $this->reload($order)->get_status());
    }

    #[TestDox('[WE2E-007] a real dispute.opened event puts the order On hold, with a note linking to the dispute')]
    public function testDispute(): void
    {
        $order = $this->paidOrder();
        $escrowId = $this->reload($order)->get_transaction_id();

        $dispute = $this->api->post(
            "/escrows/$escrowId/disputes",
            ['reason' => 'not_received', 'description' => 'Nothing was delivered by the due date.'],
            $this->api->tokenFor($order->get_billing_email()),
        );

        $this->deliver($this->realEvent('dispute.opened', StagingApi::text($dispute, 'id')));
        self::assertSame('on-hold', $this->reload($order)->get_status());
        self::assertStringContainsString(StagingApi::text($dispute, 'url'), implode("\n", $this->notes($order)));
    }
}
