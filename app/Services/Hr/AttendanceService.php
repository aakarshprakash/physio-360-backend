<?php

namespace App\Services\Hr;

use App\Enums\AttendanceStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Models\WorkingHour;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily attendance in clinic-local dates. Staff clock themselves in and out;
 * managers record or correct days. "Late" compares the first clock-in with
 * the person's first working interval of the day plus a grace period.
 */
class AttendanceService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WorkCalendar $calendar,
        private readonly LeaveCalendar $leave,
        private readonly Geofence $geofence,
    ) {}

    public function timezone(): string
    {
        return $this->context->require()->timezone;
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    public function todayFor(User $user): ?AttendanceRecord
    {
        return AttendanceRecord::query()->where('user_id', $user->id)->whereDate('work_date', $this->today()->toDateString())->first();
    }

    /**
     * @param  array{latitude: float, longitude: float, accuracy?: ?float}|null  $location
     */
    public function checkIn(User $user, ?int $branchId = null, ?array $location = null, ?string $device = null): AttendanceRecord
    {
        $site = $this->geofence->check($user, $location);

        return DB::transaction(function () use ($user, $branchId, $location, $device, $site) {
            $now = CarbonImmutable::now($this->timezone());
            $record = AttendanceRecord::query()->where('user_id', $user->id)->whereDate('work_date', $now->toDateString())->lockForUpdate()->first();

            if ($record?->check_in_at) {
                throw new DomainRuleViolation('You have already clocked in today.', 'already_checked_in');
            }

            $record ??= new AttendanceRecord(['user_id' => $user->id, 'work_date' => $now->toDateString()]);
            $record->fill([
                'branch_id' => $branchId ?? $site['branch']?->id,
                'check_in_at' => $now->utc(),
                'check_in_lat' => $location['latitude'] ?? null,
                'check_in_lng' => $location['longitude'] ?? null,
                'check_in_distance_m' => $site['distance'],
                'status' => AttendanceStatus::Present,
                'is_late' => $this->isLate($user->id, $now),
                'source' => 'self',
                'device' => $device,
            ])->save();

            return $record;
        });
    }

    /**
     * @param  array{latitude: float, longitude: float, accuracy?: ?float}|null  $location
     */
    public function checkOut(User $user, ?array $location = null): AttendanceRecord
    {
        $site = $this->geofence->check($user, $location);

        return DB::transaction(function () use ($user, $location, $site) {
            $now = CarbonImmutable::now($this->timezone());
            $record = AttendanceRecord::query()->where('user_id', $user->id)->whereDate('work_date', $now->toDateString())->lockForUpdate()->first();

            if (! $record?->check_in_at) {
                throw new DomainRuleViolation('Clock in before clocking out.', 'not_checked_in');
            }
            if ($record->check_out_at) {
                throw new DomainRuleViolation('You have already clocked out today.', 'already_checked_out');
            }

            $record->fill([
                'check_out_at' => $now->utc(),
                'check_out_lat' => $location['latitude'] ?? null,
                'check_out_lng' => $location['longitude'] ?? null,
                'check_out_distance_m' => $site['distance'],
                'minutes_worked' => (int) round($record->check_in_at->diffInMinutes($now)),
            ])->save();

            return $record;
        });
    }

    /**
     * Manager entry or correction for one person and date.
     *
     * @param  array{user_id:int, work_date:string, status:string, check_in?:?string, check_out?:?string, notes?:?string}  $data
     */
    public function record(array $data, int $actorId): AttendanceRecord
    {
        $tz = $this->timezone();
        $date = CarbonImmutable::parse($data['work_date'], $tz)->startOfDay();
        $status = AttendanceStatus::from($data['status']);

        if ($date->greaterThan($this->today())) {
            throw new DomainRuleViolation('Attendance cannot be recorded for a future date.', 'future_date');
        }

        $in = ! empty($data['check_in']) ? $date->setTimeFromTimeString($data['check_in']) : null;
        $out = ! empty($data['check_out']) ? $date->setTimeFromTimeString($data['check_out']) : null;
        if ($in && $out && $out->lessThanOrEqualTo($in)) {
            throw new DomainRuleViolation('Clock-out must be after clock-in.', 'invalid_range');
        }
        $worksThatDay = in_array($status, [AttendanceStatus::Present, AttendanceStatus::HalfDay], true);

        return DB::transaction(function () use ($data, $date, $status, $in, $out, $worksThatDay, $actorId) {
            $record = AttendanceRecord::query()->where('user_id', $data['user_id'])->whereDate('work_date', $date->toDateString())->lockForUpdate()->first()
                ?? new AttendanceRecord(['user_id' => $data['user_id'], 'work_date' => $date->toDateString()]);

            $record->fill([
                'status' => $status,
                'check_in_at' => $worksThatDay ? $in?->utc() : null,
                'check_out_at' => $worksThatDay ? $out?->utc() : null,
                'minutes_worked' => $worksThatDay && $in && $out ? (int) $in->diffInMinutes($out) : null,
                'is_late' => $worksThatDay && $in ? $this->isLate((int) $data['user_id'], $in) : false,
                'source' => 'manual',
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actorId,
            ])->save();

            return $record;
        });
    }

    /**
     * Month register: one cell per person per date, merged with leave, holidays and weekly offs.
     *
     * @param  Collection<int, User>  $users
     */
    public function register(CarbonImmutable $month, Collection $users): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth()->startOfDay();
        $today = $this->today();
        $tz = $this->timezone();
        $userIds = $users->pluck('id')->all();

        $records = AttendanceRecord::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->keyBy(fn ($r) => $r->work_date->toDateString()));
        $leave = $this->leave->between($from, $to, $userIds);
        $holidays = $this->calendar->holidays();

        $days = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'weekday' => $day->dayOfWeek,
                'weekly_off' => $this->calendar->isWeeklyOff($day),
                'holiday' => $holidays[$day->toDateString()] ?? null,
            ];
        }

        $rows = $users->map(function (User $user) use ($days, $records, $leave, $today, $tz) {
            $summary = ['present' => 0.0, 'absent' => 0.0, 'leave' => 0.0, 'late' => 0, 'minutes' => 0, 'unmarked' => 0];
            $cells = [];

            foreach ($days as $d) {
                /** @var AttendanceRecord|null $r */
                $r = $records[$user->id][$d['date']] ?? null;
                $l = $leave[$user->id][$d['date']] ?? null;
                $working = ! $d['weekly_off'] && $d['holiday'] === null;
                $past = $d['date'] <= $today->toDateString();

                $status = $r?->status->value
                    ?? ($l ? 'leave' : ($working ? ($past ? 'unmarked' : null) : ($d['holiday'] ? 'holiday' : 'off')));

                switch ($status) {
                    case 'present':
                        $summary['present'] += 1;
                        break;
                    case 'half_day':
                        $summary['present'] += 0.5;
                        $summary['absent'] += 0.5;
                        break;
                    case 'absent':
                        $summary['absent'] += 1;
                        break;
                    case 'leave':
                        $summary['leave'] += ($l['half_day'] ?? null) ? 0.5 : 1;
                        break;
                    case 'unmarked':
                        $summary['unmarked']++;
                        break;
                }
                if ($r?->is_late) {
                    $summary['late']++;
                }
                $summary['minutes'] += $r?->minutes_worked ?? 0;

                $cells[$d['date']] = [
                    'status' => $status,
                    'check_in' => $r?->check_in_at?->timezone($tz)->format('H:i'),
                    'check_out' => $r?->check_out_at?->timezone($tz)->format('H:i'),
                    'minutes' => $r?->minutes_worked,
                    'is_late' => (bool) $r?->is_late,
                    'notes' => $r?->notes,
                    'check_in_distance_m' => $r?->check_in_distance_m,
                    'device' => $r?->device,
                    'leave' => $l,
                ];
            }

            return [
                'user' => ['id' => $user->id, 'name' => $user->name, 'title' => $user->title, 'color' => $user->color],
                'cells' => $cells,
                'summary' => $summary,
            ];
        })->values()->all();

        return ['month' => $month->format('Y-m'), 'days' => $days, 'rows' => $rows];
    }

    private function isLate(int $userId, CarbonImmutable $localClockIn): bool
    {
        $firstStart = WorkingHour::query()
            ->where('user_id', $userId)
            ->where('weekday', $localClockIn->dayOfWeek)
            ->min('start_time');

        if (! $firstStart) {
            return false;
        }

        $grace = (int) $this->context->require()->setting('attendance_grace_minutes', 10);

        return $localClockIn->greaterThan($localClockIn->setTimeFromTimeString($firstStart)->addMinutes($grace));
    }
}
