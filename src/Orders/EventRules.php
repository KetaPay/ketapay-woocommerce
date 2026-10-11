<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Orders;

use KetaPay\WooCommerce\Checkout\Money;

/**
 * Decides what a KetaPay event means for a WooCommerce order:
 *
 * - escrow.funded: paid (Processing). The money waits in escrow; the store ships.
 * - escrow.completed: released to the store (Completed).
 * - escrow.refunded: returned to the buyer (Refunded).
 * - dispute.opened: On hold until KetaPay settles it; dispute.resolved puts it back to Processing,
 *   and the completed or refunded event that follows sets the final status.
 * - checkout_session.expired: an unpaid order is Cancelled.
 * - Deliveries, releases, payouts, refunds and chargebacks add an order note.
 *
 * Every rule is safe to run twice and in any order (webhooks retry and can arrive out of order).
 */
final class EventRules
{
    /** Statuses no event moves an order out of (except a refund of a completed order). */
    private const FINAL = ['completed', 'refunded'];

    /**
     * @param array<string, mixed> $object `data.object` of the event, as an array
     */
    public static function decide(string $type, array $object, ?string $milestoneId, OrderState $order): OrderUpdate
    {
        return match ($type) {
            'escrow.funded' => self::funded($object, $order),
            'escrow.completed' => self::completed($object, $order),
            'escrow.refunded' => self::refunded($object, $order),
            'dispute.opened' => self::disputeOpened($object, $order),
            'dispute.resolved' => self::disputeResolved($object, $order),
            'checkout_session.expired' => self::sessionExpired($object, $order),
            'milestone.delivered' => new OrderUpdate(notes: ['Marked as delivered on KetaPay. The buyer can now release the money or report a problem.']),
            'milestone.released' => self::milestoneReleased($object, $milestoneId),
            'payout.sent' => new OrderUpdate(notes: [
                'KetaPay sent ' . self::amount($object) . ' to your bank account ending ' . self::string($object, 'account_number_last4') . '.',
            ]),
            'payout.failed' => new OrderUpdate(notes: [
                'KetaPay could not send ' . self::amount($object) . ' to your bank: ' . self::string($object, 'failure_reason', 'no reason given')
                . '. The money is in your KetaPay balance.',
            ]),
            'refund.failed' => new OrderUpdate(notes: [
                'The refund of ' . self::amount($object) . ' to the buyer failed: ' . self::string($object, 'failure_reason', 'no reason given')
                . '. KetaPay credits their KetaPay balance instead.',
            ]),
            'chargeback.opened' => new OrderUpdate(notes: [
                'The buyer\'s bank opened a chargeback of ' . self::amount($object) . ' (' . self::string($object, 'reason') . '). KetaPay is contesting it with the escrow record.',
            ]),
            'chargeback.closed' => new OrderUpdate(notes: [
                'Chargeback closed: ' . self::string($object, 'status') . '.',
            ]),
            default => OrderUpdate::none(),
        };
    }

    /**
     * @param array<string, mixed> $escrow
     */
    private static function funded(array $escrow, OrderState $order): OrderUpdate
    {
        $escrowId = self::string($escrow, 'id');
        $paid = self::paidKobo($escrow);

        if ($order->isPaid) {
            if ($order->escrowId === $escrowId) {
                return OrderUpdate::none(); // the same payment again
            }

            // The buyer paid twice, for example an old checkout link after a newer one. Both sums sit in escrow.
            return new OrderUpdate(status: 'on-hold', notes: [
                'A second KetaPay payment of ' . Money::format($paid) . " arrived for this order (escrow $escrowId). "
                . 'The first is escrow ' . ($order->escrowId ?? 'unknown') . '. Cancel the extra one in KetaPay to refund the buyer.',
            ]);
        }

        if ($paid !== $order->totalKobo) {
            return new OrderUpdate(markPaidWithEscrow: $escrowId, status: 'on-hold', notes: [
                'Paid ' . Money::format($paid) . ' into KetaPay escrow, but the order total is ' . Money::format($order->totalKobo)
                . ". Check the order before shipping. Escrow $escrowId.",
            ]);
        }

        return new OrderUpdate(markPaidWithEscrow: $escrowId, notes: [
            'Paid ' . Money::format($paid) . " into KetaPay escrow ($escrowId). Ship the order; the money is released to you "
            . 'when the buyer confirms they received it.',
        ]);
    }

    /**
     * @param array<string, mixed> $escrow
     */
    private static function completed(array $escrow, OrderState $order): OrderUpdate
    {
        if ($order->status === 'completed') {
            return OrderUpdate::none();
        }
        $escrowId = self::string($escrow, 'id');

        return new OrderUpdate(
            markPaidWithEscrow: $order->isPaid ? null : $escrowId,
            status: 'completed',
            notes: ['The buyer released the money. KetaPay escrow ' . $escrowId . ' is complete.'],
        );
    }

    /**
     * @param array<string, mixed> $escrow
     */
    private static function refunded(array $escrow, OrderState $order): OrderUpdate
    {
        if ($order->status === 'refunded') {
            return OrderUpdate::none();
        }

        return new OrderUpdate(status: 'refunded', notes: [
            'KetaPay refunded the buyer. Escrow ' . self::string($escrow, 'id') . ' is closed.',
        ]);
    }

    /**
     * @param array<string, mixed> $dispute
     */
    private static function disputeOpened(array $dispute, OrderState $order): OrderUpdate
    {
        $by = self::string($dispute, 'opened_by', 'buyer');
        $note = ucfirst($by) . ' reported a problem (' . str_replace('_', ' ', self::string($dispute, 'reason', 'other'))
            . '). KetaPay is handling it and the money stays in escrow until it is settled: ' . self::string($dispute, 'url');

        return new OrderUpdate(status: in_array($order->status, self::FINAL, true) ? null : 'on-hold', notes: [$note]);
    }

    /**
     * @param array<string, mixed> $dispute
     */
    private static function disputeResolved(array $dispute, OrderState $order): OrderUpdate
    {
        $outcome = str_replace('_', ' ', self::string($dispute, 'outcome', 'settled'));

        return new OrderUpdate(
            status: $order->status === 'on-hold' ? 'processing' : null,
            notes: ["The problem report was settled: $outcome."],
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private static function sessionExpired(array $session, OrderState $order): OrderUpdate
    {
        // Only the order's current checkout link: an older link expiring says nothing about the order.
        if ($order->isPaid || !in_array($order->status, ['pending', 'failed'], true)
            || self::string($session, 'id') !== $order->checkoutSessionId) {
            return OrderUpdate::none();
        }

        return new OrderUpdate(status: 'cancelled', notes: ['The KetaPay checkout link expired without payment.']);
    }

    /**
     * @param array<string, mixed> $escrow
     */
    private static function milestoneReleased(array $escrow, ?string $milestoneId): OrderUpdate
    {
        $milestones = is_array($escrow['milestones'] ?? null) ? $escrow['milestones'] : [];
        foreach ($milestones as $milestone) {
            if (is_array($milestone) && ($milestone['id'] ?? null) === $milestoneId && is_int($milestone['amount'] ?? null)) {
                return new OrderUpdate(notes: ['The buyer released ' . Money::format($milestone['amount']) . ' to you.']);
            }
        }

        return new OrderUpdate(notes: ['The buyer released a payment to you.']);
    }

    /**
     * The agreed price (before KetaPay's fee): what the order total is compared with.
     *
     * @param array<string, mixed> $escrow
     */
    private static function paidKobo(array $escrow): int
    {
        return is_int($escrow['amount'] ?? null) ? $escrow['amount'] : 0;
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function amount(array $object): string
    {
        return is_int($object['amount'] ?? null) ? Money::format($object['amount']) : 'the money';
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function string(array $object, string $key, string $default = ''): string
    {
        $value = $object[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
