<?php

namespace App\Services\Hr;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;

/**
 * Approved leave lookups, kept dependency-free so scheduling can consult it
 * without pulling in the whole leave workflow.
 */
class LeaveCalendar
{
    /** Local midday: "am" half-day leave ends here, "pm" starts here. */
    public const MIDDAY = '13:00';

    /** @return 'full'|'am'|'pm'|null */
    public function coverage(int $userId, CarbonImmutable $localDate): ?string
    {
        $date = $localDate->toDateString();

        $leave = LeaveRequest::query()
            ->where('user_id', $userId)
            ->where('status', LeaveStatus::Approved)
            ->overlapping($date, $date)
            ->orderByRaw('half_day IS NOT NULL') // a full-day request wins over a half day
            ->first(['half_day']);

        return $leave ? ($leave->half_day ?? 'full') : null;
    }

    /**
     * Approved leave per user and date in a window, for registers and calendars.
     *
     * @param  list<int>|null  $userIds
     * @return array<int, array<string, array{type: string, half_day: ?string, color: string, paid: bool}>>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to, ?array $userIds = null): array
    {
        $out = [];

        LeaveRequest::query()
            ->where('status', LeaveStatus::Approved)
            ->overlapping($from->toDateString(), $to->toDateString())
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->with('type:id,name,code,color,is_paid')
            ->get()
            ->each(function (LeaveRequest $leave) use (&$out, $from, $to) {
                $start = CarbonImmutable::parse($leave->start_date->toDateString())->max($from->startOfDay());
                $end = CarbonImmutable::parse($leave->end_date->toDateString())->min($to->startOfDay());
                for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                    $out[$leave->user_id][$day->toDateString()] = [
                        'type' => $leave->type->code,
                        'half_day' => $leave->half_day,
                        'color' => $leave->type->color,
                        'paid' => $leave->type->is_paid,
                    ];
                }
            });

        return $out;
    }
}
