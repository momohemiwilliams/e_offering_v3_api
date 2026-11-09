<?php

namespace App\Support;

/**
 * Naira <-> kobo conversion. Decimal columns arrive as strings ("1500000.00");
 * the allotment engine works in integer kobo so totals reconcile exactly.
 */
class Money
{
    /**
     * @param string|int|float|null $naira
     */
    public static function toKobo($naira): int
    {
        if ($naira === null || $naira === '') {
            return 0;
        }
        $value = str_replace([',', ' ', "\u{20A6}"], '', (string) $naira);
        if (! is_numeric($value)) {
            return 0;
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-+');
        $parts = explode('.', $value, 2);
        $whole = (int) ($parts[0] === '' ? 0 : $parts[0]);
        $fraction = isset($parts[1]) ? (int) str_pad(substr($parts[1], 0, 2), 2, '0') : 0;
        $kobo = $whole * 100 + $fraction;

        return $negative ? -$kobo : $kobo;
    }

    public static function fromKobo(int $kobo): string
    {
        $negative = $kobo < 0;
        $kobo = abs($kobo);

        return ($negative ? '-' : '').intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "₦1,500,000.00" */
    public static function format($naira): string
    {
        return "\u{20A6}".number_format(static::toKobo($naira) / 100, 2);
    }
}
