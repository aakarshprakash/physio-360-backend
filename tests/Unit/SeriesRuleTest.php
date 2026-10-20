<?php

use App\Services\Scheduling\SeriesRule;
use Carbon\CarbonImmutable;

it('expands weekly occurrences in local wall-clock time', function () {
    // Monday 2026-10-19 09:00 Kolkata, Mon/Wed/Fri, 5 occurrences
    $rule = new SeriesRule(weekdays: [1, 3, 5], count: 5);
    $first = CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Kolkata');

    $dates = array_map(fn ($d) => $d->format('D Y-m-d H:i'), $rule->occurrences($first, 'Asia/Kolkata'));

    expect($dates)->toBe([
        'Mon 2026-10-19 09:00', 'Wed 2026-10-21 09:00', 'Fri 2026-10-23 09:00',
        'Mon 2026-10-26 09:00', 'Wed 2026-10-28 09:00',
    ]);
});

it('keeps local time stable across a DST change', function () {
    // Europe/London leaves BST on 2026-10-25: UTC offset changes, local time must not.
    $rule = new SeriesRule(weekdays: [5], count: 3);
    $occurrences = $rule->occurrences(CarbonImmutable::parse('2026-10-16 18:00', 'Europe/London'), 'Europe/London');

    expect(array_map(fn ($d) => $d->format('H:i'), $occurrences))->toBe(['18:00', '18:00', '18:00'])
        ->and($occurrences[0]->utc()->format('H:i'))->toBe('17:00')
        ->and($occurrences[2]->utc()->format('H:i'))->toBe('18:00');
});

it('honours interval and end date', function () {
    $rule = new SeriesRule(weekdays: [2], interval: 2, until: CarbonImmutable::parse('2026-11-30'));
    $dates = array_map(fn ($d) => $d->toDateString(), $rule->occurrences(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Kolkata'), 'Asia/Kolkata'));

    expect($dates)->toBe(['2026-10-20', '2026-11-03', '2026-11-17']);
});

it('caps runaway rules', function () {
    $rule = new SeriesRule(weekdays: [0, 1, 2, 3, 4, 5, 6], count: 500);

    expect($rule->occurrences(CarbonImmutable::parse('2026-10-20 10:00', 'UTC'), 'UTC'))->toHaveCount(SeriesRule::MAX_OCCURRENCES);
});

it('requires an end', function () {
    new SeriesRule(weekdays: [1]);
})->throws(InvalidArgumentException::class);
