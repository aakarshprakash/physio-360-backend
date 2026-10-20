<?php

namespace App\Support;

/** Best-effort E.164 normalisation for the countries Physio360 serves. */
final class PhoneNumber
{
    private const DIAL_CODES = [
        'IN' => ['91', 10], 'AE' => ['971', 9], 'SA' => ['966', 9], 'QA' => ['974', 8],
        'KW' => ['965', 8], 'BH' => ['973', 8], 'OM' => ['968', 8], 'US' => ['1', 10],
    ];

    /** "+91 98470 12345", "098470 12345", "9847012345" (IN) → "+919847012345". Null when unusable. */
    public static function e164(?string $raw, string $countryCode = 'IN'): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($raw, '+')) {
            return strlen($digits) >= 8 ? '+'.$digits : null;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        [$dial, $length] = self::DIAL_CODES[strtoupper($countryCode)] ?? ['91', 10];
        $national = ltrim($digits, '0');

        if (strlen($national) === $length) {
            return '+'.$dial.$national;
        }
        if (str_starts_with($national, $dial) && strlen($national) === strlen($dial) + $length) {
            return '+'.$national;
        }

        return null;
    }
}
