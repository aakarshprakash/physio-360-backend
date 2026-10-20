<?php

use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('converts major amounts using the currency exponent', function (string $currency, string $major, int $minor) {
    expect(Money::toMinor($major, $currency))->toBe($minor);
})->with([
    'INR whole' => ['INR', '1200', 120000],
    'INR paise' => ['INR', '1200.5', 120050],
    'INR two decimals' => ['INR', '0.07', 7],
    'KWD three decimals' => ['KWD', '32.5', 32500],
    'KWD fils' => ['KWD', '0.125', 125],
    'BHD' => ['BHD', '1.005', 1005],
    'OMR negative' => ['OMR', '-2.250', -2250],
]);

it('rejects more decimals than the currency allows', function () {
    Money::toMinor('10.123', 'INR');
})->throws(InvalidArgumentException::class);

it('rejects malformed amounts', function () {
    Money::toMinor('1,200', 'INR');
})->throws(InvalidArgumentException::class);

it('renders minor units back to a major string without floats', function () {
    expect(Money::toMajorString(120050, 'INR'))->toBe('1200.50')
        ->and(Money::toMajorString(5, 'INR'))->toBe('0.05')
        ->and(Money::toMajorString(32500, 'KWD'))->toBe('32.500')
        ->and(Money::toMajorString(-7, 'KWD'))->toBe('-0.007');
});

it('formats with the right number of fraction digits per currency', function () {
    expect(Money::format(2458000, 'INR', 'en_IN'))->toContain('24,580.00')
        ->and(Money::format(37250, 'KWD', 'en'))->toContain('37.250');
});

it('rounds tax half-up to the minor unit', function () {
    expect(Money::taxFor(10000, 1800))->toBe(1800)   // 18% of 100.00
        ->and(Money::taxFor(333, 500))->toBe(17)      // 16.65 -> 17
        ->and(Money::taxFor(310, 500))->toBe(16)      // 15.5 -> 16
        ->and(Money::taxFor(10000, 0))->toBe(0);
});
