<?php

namespace App\Services\Scheduling;

use App\Models\WorkingHour;
use App\Services\Hr\LeaveCalendar;
use Carbon\CarbonImmutable;

/** Working-hour lookups in branch-local wall-clock time, net of approved leave. */
class WorkingHours
{
    public function __construct(private readonly LeaveCalendar $leave) {}

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> local intervals for that date */
    public function intervalsFor(int $practitionerId, int $branchId, CarbonImmutable $localDate): array
    {
        $intervals = WorkingHour::query()
            ->where('user_id', $practitionerId)
            ->where('branch_id', $branchId)
            ->where('weekday', $localDate->dayOfWeek)
            ->orderBy('start_time')
            ->get()
            ->map(fn (WorkingHour $wh) => [
                $localDate->setTimeFromTimeString($wh->start_time),
                $localDate->setTimeFromTimeString($wh->end_time),
            ])
            ->all();

        return $intervals === [] ? [] : $this->withoutLeave($intervals, $practitionerId, $localDate);
    }

    public function covers(BookingRequest $request): bool
    {
        $start = $request->localStart;
        $end = $start->addMinutes($request->durationMinutes);

        foreach ($this->intervalsFor($request->practitionerId, $request->branchId, $start->startOfDay()) as [$from, $to]) {
            if ($start->greaterThanOrEqualTo($from) && $end->lessThanOrEqualTo($to)) {
                return true;
            }
        }

        return false;
    }

    /** True when approved leave overlaps the requested visit. */
    public function onLeaveDuring(BookingRequest $request): bool
    {
        $coverage = $this->leave->coverage($request->practitionerId, $request->localStart->startOfDay());
        if ($coverage === null) {
            return false;
        }
        if ($coverage === 'full') {
            return true;
        }

        $midday = $request->localStart->setTimeFromTimeString(LeaveCalendar::MIDDAY);
        $end = $request->localStart->addMinutes($request->durationMinutes);

        return $coverage === 'am' ? $request->localStart->lessThan($midday) : $end->greaterThan($midday);
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function withoutLeave(array $intervals, int $userId, CarbonImmutable $localDate): array
    {
        $coverage = $this->leave->coverage($userId, $localDate);
        if ($coverage === null) {
            return $intervals;
        }
        if ($coverage === 'full') {
            return [];
        }

        $midday = $localDate->setTimeFromTimeString(LeaveCalendar::MIDDAY);
        $clipped = [];
        foreach ($intervals as [$from, $to]) {
            [$from, $to] = $coverage === 'am' ? [$from->max($midday), $to] : [$from, $to->min($midday)];
            if ($from->lessThan($to)) {
                $clipped[] = [$from, $to];
            }
        }

        return $clipped;
    }
}
