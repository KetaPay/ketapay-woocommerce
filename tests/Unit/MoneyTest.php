<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Unit;

use KetaPay\WooCommerce\Checkout\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @return iterable<string, array{string|int|float, int}> */
    public static function amounts(): iterable
    {
        yield 'whole naira' => ['45000', 4_500_000];
        yield 'two decimals' => ['45000.00', 4_500_000];
        yield 'kobo' => ['19.99', 1999]; // as a float, 19.99 * 100 is 1998.999...
        yield 'one decimal' => ['0.5', 50];
        yield 'third decimal rounds up' => ['10.005', 1001];
        yield 'third decimal rounds down' => ['10.004', 1000];
        yield 'int' => [250, 25_000];
        yield 'spaces' => [' 100.10 ', 10_010];
        yield 'trailing dot' => ['7.', 700];
    }

    #[DataProvider('amounts')]
    public function testConvertsNairaToKobo(string|int|float $naira, int $kobo): void
    {
        self::assertSame($kobo, Money::toKobo($naira));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'negative' => ['-5.00'];
        yield 'empty' => [''];
        yield 'thousands separator' => ['45,000.00'];
        yield 'currency sign' => ['₦500'];
    }

    #[DataProvider('invalid')]
    public function testRefusesAnythingElse(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::toKobo($value);
    }

    public function testFormatsForNotes(): void
    {
        self::assertSame('₦47,000.00', Money::format(4_700_000));
        self::assertSame('₦0.50', Money::format(50));
    }
}
