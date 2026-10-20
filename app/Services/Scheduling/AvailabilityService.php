<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * Free slots for a practitioner on a local date: working hours minus busy
 * windows, on a fixed grid. Advisory only: the authoritative check happens
 * under row locks in BookingService.
 */
class AvailabilityService
{
    public const GRID_MINUTES = 15;

    public function __construct(private readonly WorkingHours $workingHours) {}

    /**
     * @return list<array{local_start: string, starts_at: string}>
     */
    public function slots(int $practitionerId, int $branchId, CarbonImmutable $localDate, string $timezone, int $durationMinutes, int $minLeadMinutes = 0): array
    {
        $day = CarbonImmutable::parse($localDate->toDateString(), $timezone);
        $intervals = $this->workingHours->intervalsFor($practitionerId, $branchId, $day);

        if ($intervals === []) {
            return [];
        }

        $busy = Appointment::query()
            ->blocking()
            ->where('practitioner_id', $practitionerId)
            ->where('blocked_from', '<', $day->endOfDay()->utc())
            ->where('blocked_until', '>', $day->startOfDay()->utc())
            ->get(['blocked_from', 'blocked_until'])
            ->map(fn ($a) => [$a->blocked_from->getTimestamp(), $a->blocked_until->getTimestamp()])
            ->all();

        $earliest = now()->addMinutes($minLeadMinutes)->getTimestamp();
        $slots = [];

        foreach ($intervals as [$from, $to]) {
            for ($start = $from; $start->addMinutes($durationMinutes)->lessThanOrEqualTo($to); $start = $start->addMinutes(self::GRID_MINUTES)) {
                $s = $start->getTimestamp();
                $e = $s + $durationMinutes * 60;

                if ($s < $earliest) {
                    continue;
                }

                foreach ($busy as [$bs, $be]) {
                    if ($s < $be && $e > $bs) {
                        continue 2;
                    }
                }

                $slots[] = [
                    'local_start' => $start->format('Y-m-d\TH:i'),
                    'starts_at' => $start->utc()->toIso8601ZuluString(),
                ];
            }
        }

        return $slots;
    }
}
