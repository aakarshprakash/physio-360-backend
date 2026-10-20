<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Weekly recurrence expanded in local wall-clock time, so a 09:00 slot stays
 * 09:00 local regardless of UTC offset changes.
 */
final class SeriesRule
{
    public const MAX_OCCURRENCES = 52;

    /**
     * @param  list<int>  $weekdays  0 = Sunday ... 6 = Saturday
     */
    public function __construct(
        public readonly array $weekdays,
        public readonly int $interval = 1,
        public readonly ?int $count = null,
        public readonly ?CarbonImmutable $until = null,
    ) {
        if ($weekdays === [] || array_diff($weekdays, range(0, 6)) !== []) {
            throw new InvalidArgumentException('Weekdays must be between 0 and 6.');
        }
        if ($count === null && $until === null) {
            throw new InvalidArgumentException('A series needs a count or an end date.');
        }
    }

    /** @return list<CarbonImmutable> local start times, in the given timezone */
    public function occurrences(CarbonImmutable $firstLocalStart, string $timezone): array
    {
        $first = CarbonImmutable::parse($firstLocalStart->format('Y-m-d H:i:s'), $timezone);
        $time = $first->format('H:i:s');
        $weekStart = $first->startOfWeek(CarbonImmutable::SUNDAY);
        $limit = min($this->count ?? self::MAX_OCCURRENCES, self::MAX_OCCURRENCES);

        $result = [];
        $date = $first->startOfDay();

        // Hard stop at one year to bound the loop even for sparse rules.
        $horizon = $first->addYear();

        while (count($result) < $limit && $date->lessThanOrEqualTo($horizon)) {
            if ($this->until !== null && $date->toDateString() > $this->until->toDateString()) {
                break;
            }

            $weekIndex = intdiv((int) $weekStart->diffInDays($date->startOfWeek(CarbonImmutable::SUNDAY)), 7);

            if ($weekIndex % $this->interval === 0 && in_array($date->dayOfWeek, $this->weekdays, true)) {
                $result[] = CarbonImmutable::parse($date->toDateString().' '.$time, $timezone);
            }

            $date = $date->addDay();
        }

        return $result;
    }
}
