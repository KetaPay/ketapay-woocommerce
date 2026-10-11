<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Unit;

use KetaPay\WooCommerce\Checkout\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function nigerianMobiles(): iterable
    {
        yield 'local with spaces' => ['0801 234 5678'];
        yield 'local' => ['08012345678'];
        yield 'e164' => ['+2348012345678'];
        yield 'international with dashes' => ['+234 801-234-5678'];
        yield 'no plus' => ['2348012345678'];
        yield 'no leading zero' => ['8012345678'];
        yield 'trunk zero after country code' => ['+234 0801 234 5678'];
    }

    #[DataProvider('nigerianMobiles')]
    public function testNormalisesNigerianMobiles(string $typed): void
    {
        self::assertSame('+2348012345678', Phone::normalise($typed));
    }

    /** @return iterable<string, array{?string}> */
    public static function notAccepted(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'landline' => ['01 234 5678'];
        yield 'uk mobile' => ['+44 7700 900123'];
        yield 'too short' => ['0801234'];
        yield 'too long' => ['080123456789'];
        yield 'letters' => ['call me'];
    }

    #[DataProvider('notAccepted')]
    public function testReturnsNullForAnythingTheApiWouldReject(?string $typed): void
    {
        self::assertNull(Phone::normalise($typed));
    }
}
