<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Integration;

use KetaPay\WooCommerce\Orders\OrderSync;
use KetaPay\WooCommerce\Tests\Integration\Support\IntegrationTestCase;

final class WebhookTest extends IntegrationTestCase
{
    private function sentToKetaPay(\WC_Order $order): \WC_Order
    {
        $order->update_meta_data(OrderSync::META_SESSION, 'cs_' . $order->get_id());
        $order->save();

        return $order;
    }

    public function testFundedMarksTheOrderPaid(): void
    {
        $order = $this->sentToKetaPay($this->order());

        $response = $this->webhook('escrow.funded', $this->escrow($order));

        self::assertSame(200, $response->get_status());
        $order = $this->reload($order);
        self::assertSame('processing', $order->get_status());
        self::assertTrue($order->is_paid());
        self::assertSame('esc_' . $order->get_id(), $order->get_transaction_id());
        self::assertSame('esc_' . $order->get_id(), $order->get_meta(OrderSync::META_ESCROW));
        self::assertStringContainsString('into KetaPay escrow', implode("\n", $this->notes($order)));
    }

    public function testARetriedEventIsAppliedOnce(): void
    {
        $order = $this->sentToKetaPay($this->order());
        $this->webhook('escrow.funded', $this->escrow($order), 'evt_same_' . $order->get_id());
        $notes = count($this->notes($this->reload($order)));

        $response = $this->webhook('escrow.funded', $this->escrow($order), 'evt_same_' . $order->get_id());

        self::assertSame(200, $response->get_status());
        self::assertCount($notes, $this->notes($this->reload($order)));
    }

    public function testABadSignatureIsRejectedAndChangesNothing(): void
    {
        $order = $this->sentToKetaPay($this->order());

        $response = $this->webhook('escrow.funded', $this->escrow($order), secret: 'whsec_wrong');

        self::assertSame(400, $response->get_status());
        self::assertFalse($this->reload($order)->is_paid());
    }

    public function testNoSecretAnswers500SoKetaPayRetriesLater(): void
    {
        $order = $this->sentToKetaPay($this->order());
        $settings = get_option('woocommerce_ketapay_settings');
        self::assertIsArray($settings);
        update_option('woocommerce_ketapay_settings', ['webhook_secret' => ''] + $settings);

        self::assertSame(500, $this->webhook('escrow.funded', $this->escrow($order))->get_status());
        self::assertFalse($this->reload($order)->is_paid());
    }

    public function testAnotherStoresEventIsIgnored(): void
    {
        $order = $this->sentToKetaPay($this->order());
        $escrow = $this->escrow($order, ['metadata' => ['wc_order_id' => (string) $order->get_id(), 'wc_site' => 'some-other-store']]);

        $response = $this->webhook('escrow.funded', $escrow);

        self::assertSame(200, $response->get_status()); // acknowledged, so KetaPay doesn't retry it
        self::assertFalse($this->reload($order)->is_paid());
    }

    public function testReleasedMoneyCompletesTheOrder(): void
    {
        $order = $this->sentToKetaPay($this->order());
        $this->webhook('escrow.funded', $this->escrow($order));

        $this->webhook('escrow.completed', $this->escrow($order, ['status' => 'completed']));

        self::assertSame('completed', $this->reload($order)->get_status());
    }

    public function testRefundRefundsTheOrderAndRecordsTheRefund(): void
    {
        $order = $this->sentToKetaPay($this->order());
        $this->webhook('escrow.funded', $this->escrow($order));

        $this->webhook('escrow.refunded', $this->escrow($order, ['status' => 'refunded']));

        $order = $this->reload($order);
        self::assertSame('refunded', $order->get_status());
        self::assertSame((float) $order->get_total(), (float) $order->get_total_refunded());
    }

    public function testADisputeFindsTheOrderThroughItsEscrowAndHoldsIt(): void
    {
        $order = $this->sentToKetaPay($this->order());
        $this->webhook('escrow.funded', $this->escrow($order));
        $this->api->on('GET', '/escrows/esc_' . $order->get_id(), 200, $this->escrow($order));

        $this->webhook('dispute.opened', [
            'object' => 'dispute', 'id' => 'dsp_1', 'escrow_id' => 'esc_' . $order->get_id(),
            'opened_by' => 'buyer', 'reason' => 'not_received', 'url' => 'https://app.ketapay.com/disputes/dsp_1',
        ]);

        self::assertSame('on-hold', $this->reload($order)->get_status());
        self::assertSame(['GET /escrows/esc_' . $order->get_id()], $this->api->calls());
    }

    public function testADisputeBeforeThePaymentWebhookAsksKetaPayWhichOrderItIs(): void
    {
        $order = $this->sentToKetaPay($this->order());
        $this->api->on('GET', '/escrows/esc_' . $order->get_id(), 200, $this->escrow($order));

        $this->webhook('dispute.opened', [
            'object' => 'dispute', 'id' => 'dsp_2', 'escrow_id' => 'esc_' . $order->get_id(),
            'opened_by' => 'buyer', 'reason' => 'damaged', 'url' => 'https://app.ketapay.com/disputes/dsp_2',
        ]);

        self::assertSame(['GET /escrows/esc_' . $order->get_id()], $this->api->calls());
        self::assertSame('on-hold', $this->reload($order)->get_status());
    }

    public function testAnExpiredLinkCancelsTheOrder(): void
    {
        $order = $this->sentToKetaPay($this->order());
        $session = 'cs_' . $order->get_id();
        // Cancelling the order asks KetaPay to close the link, which has already expired.
        $this->api->on('POST', "/checkout_sessions/$session/cancel", 409, [
            'error' => ['type' => 'invalid_request_error', 'code' => 'checkout_session_not_open', 'message' => 'Session expired'],
        ]);

        $response = $this->webhook('checkout_session.expired', $this->session($order, ['status' => 'expired']));

        self::assertSame(200, $response->get_status());
        self::assertSame('cancelled', $this->reload($order)->get_status());
    }

    public function testADisputeNeverLandsOnAnotherOrder(): void
    {
        // Regression: the order was looked up with wc_get_orders + meta_query, which some order storage
        // setups ignore, returning the newest KetaPay order instead of the dispute's.
        $disputed = $this->sentToKetaPay($this->order());
        $this->webhook('escrow.funded', $this->escrow($disputed));
        $newest = $this->sentToKetaPay($this->order());
        $this->webhook('escrow.funded', $this->escrow($newest));
        $this->api->on('GET', '/escrows/esc_' . $disputed->get_id(), 200, $this->escrow($disputed));

        $this->webhook('dispute.opened', [
            'object' => 'dispute', 'id' => 'dsp_3', 'escrow_id' => 'esc_' . $disputed->get_id(),
            'opened_by' => 'buyer', 'reason' => 'damaged', 'url' => 'https://app.ketapay.com/disputes/dsp_3',
        ]);

        self::assertSame('on-hold', $this->reload($disputed)->get_status());
        self::assertSame('processing', $this->reload($newest)->get_status());
    }

    public function testFailingToProcessAnswers500SoKetaPayRetries(): void
    {
        $order = $this->sentToKetaPay($this->order());
        // KetaPay can't be reached to find the escrow's order: try again later.
        $this->api->on('GET', '/escrows/esc_unknown', 503, ['error' => ['type' => 'api_error', 'code' => 'unavailable', 'message' => 'down']]);

        $response = $this->webhook('payout.sent', ['object' => 'payout', 'escrow_id' => 'esc_unknown', 'amount' => 1]);

        self::assertSame(500, $response->get_status());
        self::assertSame('pending', $this->reload($order)->get_status());
    }
}
