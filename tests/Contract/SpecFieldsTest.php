<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Webhooks can't go through Prism, so this checks the other direction: every field the plugin reads
 * from an event is one the spec says is always there, and every event type it handles exists. A spec
 * change that renames or drops one fails here.
 */
final class SpecFieldsTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $schemas = null;

    /** @return iterable<string, array{string, list<string>}> */
    public static function fieldsRead(): iterable
    {
        // EventRules, OrderSync and Gateway read exactly these.
        yield 'Escrow' => ['Escrow', ['id', 'object', 'amount', 'milestones', 'checkout_session_id', 'metadata']];
        yield 'Milestone' => ['Milestone', ['id', 'amount']];
        yield 'CheckoutSession' => ['CheckoutSession', ['id', 'object', 'status', 'url', 'amount', 'escrow_id', 'metadata']];
        yield 'Dispute' => ['Dispute', ['object', 'escrow_id', 'opened_by', 'reason', 'outcome', 'url']];
        yield 'Payout' => ['Payout', ['object', 'escrow_id', 'amount', 'account_number_last4', 'failure_reason']];
        yield 'Refund' => ['Refund', ['object', 'escrow_id', 'amount', 'failure_reason']];
        yield 'Chargeback' => ['Chargeback', ['object', 'escrow_id', 'amount', 'reason', 'status']];
        yield 'WebhookEndpoint' => ['WebhookEndpoint', ['id', 'url']];
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('fieldsRead')]
    public function testEveryFieldThePluginReadsIsRequiredInTheSpec(string $schema, array $fields): void
    {
        $required = self::strings(self::schema($schema)['required'] ?? null);

        self::assertSame([], array_values(array_diff($fields, $required)), "$schema fields the plugin reads but the spec doesn't promise");
    }

    public function testEveryEventTypeThePluginHandlesExists(): void
    {
        $handled = [
            'escrow.funded', 'escrow.completed', 'escrow.refunded', 'dispute.opened', 'dispute.resolved',
            'checkout_session.expired', 'milestone.delivered', 'milestone.released', 'payout.sent', 'payout.failed',
            'refund.failed', 'chargeback.opened', 'chargeback.closed',
        ];
        $types = self::strings(self::schema('EventType')['enum'] ?? null);

        self::assertSame([], array_values(array_diff($handled, $types)));
    }

    public function testMetadataTakesTheTwoKeysThePluginStamps(): void
    {
        $metadata = self::schema('Metadata');

        self::assertGreaterThanOrEqual(2, $metadata['maxProperties'] ?? PHP_INT_MAX);
        $nameMax = is_array($metadata['propertyNames'] ?? null) ? ($metadata['propertyNames']['maxLength'] ?? PHP_INT_MAX) : PHP_INT_MAX;
        self::assertGreaterThanOrEqual(strlen('wc_order_id'), $nameMax);
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        self::assertIsArray($values);

        return array_values(array_filter($values, 'is_string'));
    }

    /** @return array<string, mixed> */
    private static function schema(string $name): array
    {
        if (self::$schemas === null) {
            $path = getenv('KETAPAY_SPEC') ?: dirname(__DIR__, 3) . '/ketapay-php/spec/v1.yaml';
            if (!is_file($path)) {
                self::markTestSkipped("Spec not found at $path: run `composer test:contract`");
            }
            $spec = Yaml::parseFile($path);
            $components = is_array($spec) && is_array($spec['components'] ?? null) ? $spec['components'] : [];
            $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
            self::$schemas = [];
            foreach ($schemas as $key => $value) {
                self::$schemas[(string) $key] = $value;
            }
        }
        $schema = self::$schemas[$name] ?? null;
        self::assertIsArray($schema, "No $name schema in the spec");
        $fields = [];
        foreach ($schema as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }
}
