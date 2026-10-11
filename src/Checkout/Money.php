<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Checkout;

/** Converts WooCommerce's decimal amounts to the kobo integers the KetaPay API takes. */
final class Money
{
    /**
     * WooCommerce stores totals as decimal strings ("45000.00"). Parsed as a string, not a float, so
     * "19.99" is always 1999 kobo. Anything past two decimals is rounded half up.
     */
    public static function toKobo(string|float|int $amount): int
    {
        $value = trim((string) $amount);
        if (!preg_match('/^(\d+)(?:\.(\d*))?$/', $value, $m)) {
            throw new \InvalidArgumentException("Not a positive amount: \"$value\"");
        }
        $decimals = str_pad($m[2] ?? '', 3, '0');
        $kobo = (int) $m[1] * 100 + (int) substr($decimals, 0, 2);

        return (int) $decimals[2] >= 5 ? $kobo + 1 : $kobo;
    }

    /** "₦45,000.00", for order notes. */
    public static function format(int $kobo): string
    {
        return '₦' . number_format($kobo / 100, 2);
    }
}
