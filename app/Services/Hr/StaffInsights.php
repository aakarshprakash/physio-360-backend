<?php

namespace App\Services\Hr;

use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LeaveStatus;
use App\Models\Appointment;
use App\Models\AttendanceRecord;
use App\Models\Feedback;
use App\Models\InvoiceItem;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read models for team management: who is in today, and per-person
 * performance, attendance and leave figures for the staff profile.
 */
class StaffInsights
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AttendanceService $attendance,
        private readonly LeaveCalendar $leave,
        private readonly LeaveService $leaves,
    ) {}

    /**
     * Today's presence per user id: in | out | leave | absent (not clocked in yet).
     *
     * @param  list<int>  $userIds
     * @return array<int, array{state: string, check_in: ?string, check_out: ?string, is_late: bool, leave: ?string}>
     */
    public function presenceToday(array $userIds): array
    {
        $today = $this->attendance->today();
        $tz = $this->attendance->timezone();
        $records = AttendanceRecord::query()->whereIn('user_id', $userIds)->whereDate('work_date', $today->toDateString())->get()->keyBy('user_id');
        $leave = $this->leave->between($today, $today, $userIds);

        $out = [];
        foreach ($userIds as $id) {
            $r = $records[$id] ?? null;
            $l = $leave[$id][$today->toDateString()] ?? null;
            $state = match (true) {
                $r?->check_in_at !== null && $r->check_out_at === null => 'in',
                $r?->check_out_at !== null => 'out',
                $l !== null || $r?->status?->value === 'leave' => 'leave',
                default => 'absent',
            };
            $out[$id] = [
                'state' => $state,
                'check_in' => $r?->check_in_at?->timezone($tz)->format('H:i'),
                'check_out' => $r?->check_out_at?->timezone($tz)->format('H:i'),
                'is_late' => (bool) $r?->is_late,
                'leave' => $l['type'] ?? null,
            ];
        }

        return $out;
    }

    /** Team headline numbers for the staff directory. */
    public function teamSummary(Collection $activeUsers): array
    {
        $presence = $this->presenceToday($activeUsers->pluck('id')->all());
        $states = collect($presence)->countBy('state');

        return [
            'total' => $activeUsers->count(),
            'practitioners' => $activeUsers->where('is_practitioner', true)->count(),
            'clocked_in' => ($states['in'] ?? 0) + ($states['out'] ?? 0),
            'on_leave' => $states['leave'] ?? 0,
            'not_in' => $states['absent'] ?? 0,
            'late' => collect($presence)->where('is_late', true)->count(),
            'pending_leave' => LeaveRequest::query()->where('status', LeaveStatus::Pending)->count(),
        ];
    }

    /** Month figures for one person (clinic-local month). */
    public function profileStats(User $user, CarbonImmutable $month): array
    {
        $tz = $this->attendance->timezone();
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $fromUtc = $from->utc();
        $toUtc = $to->utc();

        $appointments = Appointment::query()
            ->where('practitioner_id', $user->id)
            ->whereBetween('starts_at', [$fromUtc, $toUtc])
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $completed = (int) ($appointments[AppointmentStatus::Completed->value] ?? 0);
        $total = (int) $appointments->sum();

        $patientsSeen = Appointment::query()
            ->where('practitioner_id', $user->id)
            ->where('status', AppointmentStatus::Completed)
            ->whereBetween('starts_at', [$fromUtc, $toUtc])
            ->distinct()
            ->count('patient_id');

        $revenue = (int) InvoiceItem::query()
            ->join('appointments', 'appointments.id', '=', 'invoice_items.appointment_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('appointments.practitioner_id', $user->id)
            ->whereIn('invoices.status', [InvoiceStatus::Posted->value, InvoiceStatus::Credited->value])
            ->whereBetween('invoices.issue_date', [$from->toDateString(), $to->toDateString()])
            ->sum('invoice_items.total_minor');

        $ratings = Feedback::query()->where('practitioner_id', $user->id);
        $ratingCount = (clone $ratings)->count();

        $upcoming = Appointment::query()
            ->where('practitioner_id', $user->id)
            ->whereIn('status', [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed])
            ->whereBetween('starts_at', [now(), now()->addDays(7)])
            ->count();

        $register = $this->attendance->register($month, collect([$user]));

        return [
            'month' => $month->format('Y-m'),
            'timezone' => $tz,
            'appointments' => [
                'total' => $total,
                'completed' => $completed,
                'cancelled' => (int) ($appointments[AppointmentStatus::Cancelled->value] ?? 0),
                'no_show' => (int) ($appointments[AppointmentStatus::NoShow->value] ?? 0),
                'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : null,
                'upcoming_7_days' => $upcoming,
            ],
            'patients_seen' => $patientsSeen,
            'revenue_minor' => $revenue,
            'rating' => [
                'average' => $ratingCount ? round((float) (clone $ratings)->avg('rating'), 1) : null,
                'count' => $ratingCount,
            ],
            'attendance' => $register['rows'][0]['summary'] ?? null,
            'attendance_days' => collect($register['rows'][0]['cells'] ?? [])->map(fn ($c, $date) => ['date' => $date] + $c)->values(),
            'leave_balances' => $this->leaves->balances($user->id, (int) $month->format('Y')),
            'presence_today' => $this->presenceToday([$user->id])[$user->id],
        ];
    }
}
