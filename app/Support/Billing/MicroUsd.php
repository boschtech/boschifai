<?php

namespace App\Support\Billing;

use InvalidArgumentException;

/**
 * Integer micro-dollar arithmetic (1 USD = 1 000 000). Amounts are parsed from decimal strings
 * digit by digit — never through a float — so US$0.1 is exactly 100 000.
 */
final class MicroUsd
{
    public const PER_USD = 1_000_000;

    /**
     * Parses a decimal amount ("12", "0.1234", "-1.5") into millionths. Accepts a float only for
     * values the CLI reports as floats (costs); it is formatted to 6 places first.
     */
    public static function parse(string|int|float $amount): int
    {
        if (is_int($amount)) {
            return $amount * self::PER_USD;
        }

        $text = is_float($amount) ? sprintf('%.6F', $amount) : trim($amount);
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,6})\d*)?$/', $text, $m)) {
            throw new InvalidArgumentException("\"{$text}\" is not a decimal amount.");
        }

        $micro = (int) $m[2] * self::PER_USD + (int) str_pad($m[3] ?? '', 6, '0');

        return $m[1] === '-' ? -$micro : $micro;
    }

    /** Like parse(), but null/'' (an unset config value) stays null. */
    public static function parseOptional(string|int|float|null $amount): ?int
    {
        return $amount === null || $amount === '' ? null : self::parse($amount);
    }

    /** $micro × $factorMillionths / 1 000 000, rounded half up. Both must be ≥ 0. */
    public static function multiply(int $micro, int $factorMillionths): int
    {
        if ($micro < 0 || $factorMillionths < 0) {
            throw new InvalidArgumentException('Only non-negative amounts can be multiplied.');
        }

        return intdiv($micro * $factorMillionths + self::PER_USD / 2, self::PER_USD);
    }

    /** "12.345600" style, for logs and reports. */
    public static function format(int $micro, int $places = 6): string
    {
        $sign = $micro < 0 ? '-' : '';
        $abs = abs($micro);
        $decimals = substr(str_pad((string) ($abs % self::PER_USD), 6, '0', STR_PAD_LEFT), 0, $places);

        return $sign.intdiv($abs, self::PER_USD).($places > 0 ? '.'.$decimals : '');
    }
}
