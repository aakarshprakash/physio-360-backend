<?php

namespace App\Services\Hr;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The clinic's working calendar: weekly off days and declared holidays
 * (tenant settings "weekly_off_days" and "holidays"). Used for leave day
 * counts, the attendance register and payroll proration.
 */
class WorkCalendar
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return list<int> weekday numbers, 0 = Sunday */
    public function weeklyOffDays(): array
    {
        return array_map('intval', (array) $this->context->require()->setting('weekly_off_days', [0]));
    }

    /** @return array<string, string> Y-m-d => holiday name */
    public function holidays(): array
    {
        return collect((array) $this->context->require()->setting('holidays', []))
            ->filter(fn ($h) => is_array($h) && isset($h['date']))
            ->mapWithKeys(fn ($h) => [(string) $h['date'] => (string) ($h['name'] ?? '')])
            ->all();
    }

    public function isWeeklyOff(CarbonImmutable $date): bool
    {
        return in_array($date->dayOfWeek, $this->weeklyOffDays(), true);
    }

    public function holidayName(CarbonImmutable $date): ?string
    {
        return $this->holidays()[$date->toDateString()] ?? null;
    }

    public function isWorkingDay(CarbonImmutable $date): bool
    {
        return ! $this->isWeeklyOff($date) && $this->holidayName($date) === null;
    }

    /** Working days in [from, to], both inclusive local dates. */
    public function workingDaysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $offs = $this->weeklyOffDays();
        $holidays = $this->holidays();
        $count = 0;

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeek, $offs, true) && ! isset($holidays[$day->toDateString()])) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<CarbonImmutable> */
    public function workingDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];
        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if ($this->isWorkingDay($day)) {
                $dates[] = $day;
            }
        }

        return $dates;
    }
}
