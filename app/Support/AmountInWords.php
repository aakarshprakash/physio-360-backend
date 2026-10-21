<?php

namespace App\Support;

/**
 * "Rupees Twelve Thousand Five Hundred and Paise Fifty Only" — the line Indian
 * and Gulf invoices carry under the total. INR uses lakh/crore grouping.
 */
final class AmountInWords
{
    private const UNITS = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /** currency => [major name, minor name] */
    private const NAMES = [
        'INR' => ['Rupees', 'Paise'], 'AED' => ['Dirhams', 'Fils'], 'SAR' => ['Riyals', 'Halalas'],
        'QAR' => ['Riyals', 'Dirhams'], 'KWD' => ['Dinars', 'Fils'], 'BHD' => ['Dinars', 'Fils'],
        'OMR' => ['Rials', 'Baisa'], 'USD' => ['Dollars', 'Cents'],
    ];

    public static function format(int $minor, string $currency): string
    {
        $exponent = Money::exponent($currency);
        $minor = abs($minor);
        $factor = 10 ** $exponent;
        $major = intdiv($minor, $factor);
        $fraction = $minor % $factor;
        [$majorName, $minorName] = self::NAMES[$currency] ?? [$currency, ''];
        $indian = $currency === 'INR';

        $words = $majorName.' '.($major === 0 ? 'Zero' : self::number($major, $indian));
        if ($fraction > 0 && $minorName !== '') {
            $words .= ' and '.$minorName.' '.self::number($fraction, $indian);
        }

        return $words.' Only';
    }

    private static function number(int $n, bool $indian): string
    {
        $groups = $indian
            ? [[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']]
            : [[1000000000, 'Billion'], [1000000, 'Million'], [1000, 'Thousand'], [100, 'Hundred']];

        $parts = [];
        foreach ($groups as [$size, $name]) {
            if ($n >= $size) {
                $parts[] = self::number(intdiv($n, $size), $indian).' '.$name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $parts[] = $n < 20 ? self::UNITS[$n] : trim(self::TENS[intdiv($n, 10)].' '.self::UNITS[$n % 10]);
        }

        return implode(' ', $parts);
    }
}
