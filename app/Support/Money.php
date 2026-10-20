<?php

namespace App\Support;

use App\Models\Currency;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Integer minor-unit arithmetic with a per-currency exponent (INR 2, KWD/BHD/OMR 3).
 * Never uses floats for stored amounts.
 */
final class Money
{
    public static function exponent(string $currency): int
    {
        return Cache::rememberForever('currency:exponent:'.$currency, function () use ($currency) {
            $exponent = Currency::query()->whereKey($currency)->value('exponent');

            if ($exponent === null) {
                throw new InvalidArgumentException("Unknown currency {$currency}");
            }

            return (int) $exponent;
        });
    }

    /** "1234.5" (major units, as entered) -> 123450 for INR / 1234500 for KWD. */
    public static function toMinor(string|int $major, string $currency): int
    {
        $exponent = self::exponent($currency);
        $value = trim((string) $major);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid amount '{$value}'");
        }

        $fraction = $m[3] ?? '';
        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException("Amount '{$value}' has more than {$exponent} decimal places");
        }

        $minor = (int) ($m[2].str_pad($fraction, $exponent, '0'));

        return ($m[1] ?? '') === '-' ? -$minor : $minor;
    }

    /** 123450 -> "1234.50" */
    public static function toMajorString(int $minor, string $currency): string
    {
        $exponent = self::exponent($currency);
        $sign = $minor < 0 ? '-' : '';
        $abs = (string) abs($minor);

        if ($exponent === 0) {
            return $sign.$abs;
        }

        $abs = str_pad($abs, $exponent + 1, '0', STR_PAD_LEFT);

        return $sign.substr($abs, 0, -$exponent).'.'.substr($abs, -$exponent);
    }

    public static function format(int $minor, string $currency, string $locale = 'en_IN'): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $exponent = self::exponent($currency);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $exponent);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $exponent);

        return $formatter->formatCurrency((float) self::toMajorString($minor, $currency), $currency);
    }

    /** Tax on an amount at a rate in basis points, rounded half-up to the minor unit. */
    public static function taxFor(int $amountMinor, int $rateBasisPoints): int
    {
        return intdiv($amountMinor * $rateBasisPoints + 5000, 10000);
    }
}
