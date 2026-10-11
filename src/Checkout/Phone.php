<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Checkout;

/** Turns the phone numbers people type at checkout into the E.164 form the KetaPay API accepts. */
final class Phone
{
    /** The API's own rule: a Nigerian mobile number. */
    private const PATTERN = '/^\+234[789][01]\d{8}$/';

    /**
     * "0801 234 5678", "+234 801-234-5678", "2348012345678" and "8012345678" all become
     * "+2348012345678". Anything else (landlines, foreign numbers, typos) returns null: the buyer is
     * then identified by email alone rather than failing the checkout.
     */
    public static function normalise(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '234')) {
            $digits = substr($digits, 3);
        }
        $digits = ltrim($digits, '0');
        $e164 = '+234' . $digits;

        return preg_match(self::PATTERN, $e164) === 1 ? $e164 : null;
    }
}
