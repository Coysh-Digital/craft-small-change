<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\helpers;

use Craft;

/**
 * Amounts are integers in minor units everywhere; this is the only place that
 * converts to and from what a person types or reads.
 */
final class Money
{
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'OMR', 'TND'];

    /**
     * Stripe takes amounts up to eight digits in the minor unit.
     */
    public const MAX_MINOR = 99999999;

    public static function exponent(string $currency): int
    {
        $currency = strtoupper($currency);

        return match (true) {
            in_array($currency, self::ZERO_DECIMAL, true) => 0,
            in_array($currency, self::THREE_DECIMAL, true) => 3,
            default => 2,
        };
    }

    /**
     * Parses what a donor typed ("10", "10.50", "£1,000") into minor units.
     * Returns null for anything that isn't a plain positive amount, including
     * more decimal places than the currency has.
     */
    public static function parse(mixed $input, string $currency): ?int
    {
        if (is_int($input)) {
            $input = (string) $input;
        }

        if (!is_string($input) && !is_float($input)) {
            return null;
        }

        $value = trim((string) $input);
        $value = preg_replace('/[\s£$€]/u', '', $value) ?? '';

        // A comma is only a thousands separator. "10,50" is far more likely a decimal mark
        // than ten thousand five hundred pounds, so it is rejected rather than guessed at.
        if (str_contains($value, ',')) {
            if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $value) !== 1) {
                return null;
            }
            $value = str_replace(',', '', $value);
        }

        if ($value === '' || preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        $exponent = self::exponent($currency);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        if (strlen($fraction) > $exponent) {
            // "10.500" is fine, "10.505" is not.
            if (trim(substr($fraction, $exponent), '0') !== '') {
                return null;
            }
            $fraction = substr($fraction, 0, $exponent);
        }

        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > 9) {
            return null;
        }

        $minor = (int) ($whole . str_pad($fraction, $exponent, '0'));

        return $minor > 0 && $minor <= self::MAX_MINOR ? $minor : null;
    }

    /**
     * A plain decimal string for form values, such as "10.50".
     */
    public static function toDecimal(int $minor, string $currency): string
    {
        $exponent = self::exponent($currency);

        if ($exponent === 0) {
            return (string) $minor;
        }

        $negative = $minor < 0;
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);
        $decimal = substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);

        return ($negative ? '-' : '') . $decimal;
    }

    /**
     * A formatted amount for display, such as "£10.50".
     */
    public static function format(int $minor, string $currency, bool $stripZeros = false): string
    {
        return Craft::$app->getFormatter()->asCurrency((float) self::toDecimal($minor, $currency), strtoupper($currency), [], [], $stripZeros);
    }
}
