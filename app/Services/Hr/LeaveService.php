<?php

namespace App\Services\Hr;

use App\Enums\AppointmentStatus;
use App\Enums\LeaveStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Appointment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Leave requests: day counting on the clinic calendar, balance and overlap
 * rules, and the approval workflow. Approved leave removes the person's
 * bookable hours (see WorkingHours) and shows on the attendance register.
 */
class LeaveService
{
    public function __construct(private readonly WorkCalendar $calendar) {}

    /**
     * @param  array{leave_type_id:int, start_date:string, end_date:string, half_day?:?string, reason?:?string}  $data
     */
    public function request(User $user, array $data, int $actorId): LeaveRequest
    {
        return DB::transaction(function () use ($user, $data, $actorId) {
            // Serialise requests per person so two submissions cannot both pass the balance check.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $type = LeaveType::query()->where('is_active', true)->findOrFail($data['leave_type_id']);
            $from = CarbonImmutable::parse($data['start_date']);
            $to = CarbonImmutable::parse($data['end_date']);
            $halfDay = $data['half_day'] ?? null;

            if ($to->lessThan($from)) {
                throw new DomainRuleViolation('The leave ends before it starts.', 'invalid_range');
            }
            if ($halfDay !== null && ! $from->isSameDay($to)) {
                throw new DomainRuleViolation('Half-day leave must be a single date.', 'invalid_half_day');
            }

            $days = $halfDay !== null
                ? ($this->calendar->isWorkingDay($from) ? 0.5 : 0.0)
                : (float) $this->calendar->workingDaysBetween($from, $to);

            if ($days <= 0) {
                throw new DomainRuleViolation('The selected dates are not working days.', 'no_working_days');
            }

            $overlap = LeaveRequest::query()
                ->where('user_id', $user->id)
                ->whereIn('status', LeaveStatus::holdingValues())
                ->overlapping($from->toDateString(), $to->toDateString())
                ->get(['half_day']);

            // Two half days (am + pm) on the same date may coexist; anything else overlapping may not.
            $clash = $overlap->contains(fn ($l) => $halfDay === null || $l->half_day === null || $l->half_day === $halfDay);
            if ($clash) {
                throw new DomainRuleViolation('This overlaps another leave request.', 'overlapping_leave');
            }

            if ($type->isCapped()) {
                $balance = $this->balanceFor($user->id, $type, $from->year);
                if ($days > $balance['remaining']) {
                    throw new DomainRuleViolation('Not enough leave balance.', 'insufficient_balance', ['remaining' => $balance['remaining']]);
                }
            }

            $leave = new LeaveRequest([
                'user_id' => $user->id,
                'leave_type_id' => $type->id,
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
                'half_day' => $halfDay,
                'days' => $days,
                'reason' => $data['reason'] ?? null,
                'created_by' => $actorId,
            ]);
            $leave->save();

            return $leave;
        });
    }

    public function approve(LeaveRequest $leave, User $actor, ?string $note = null): LeaveRequest
    {
        return $this->decide($leave, $actor, LeaveStatus::Approved, $note);
    }

    public function reject(LeaveRequest $leave, User $actor, string $note): LeaveRequest
    {
        return $this->decide($leave, $actor, LeaveStatus::Rejected, $note);
    }

    /** The requester (or an approver) withdraws a pending or approved request. */
    public function cancel(LeaveRequest $leave, User $actor): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $actor) {
            $locked = LeaveRequest::query()->lockForUpdate()->findOrFail($leave->id);
            if (! in_array($locked->status->value, LeaveStatus::holdingValues(), true)) {
                throw new DomainRuleViolation('Only pending or approved leave can be cancelled.', 'invalid_state');
            }

            $locked->forceFill(['status' => LeaveStatus::Cancelled, 'decided_by' => $actor->id, 'decided_at' => now()])->save();

            return $locked;
        });
    }

    /**
     * @return list<array{leave_type_id:int, name:string, code:string, color:string, is_paid:bool, quota:float, used:float, pending:float, remaining:?float}>
     */
    public function balances(int $userId, int $year): array
    {
        return LeaveType::query()->where('is_active', true)->orderBy('name')->get()
            ->map(function (LeaveType $type) use ($userId, $year) {
                $b = $this->balanceFor($userId, $type, $year);

                return [
                    'leave_type_id' => $type->id,
                    'name' => $type->name,
                    'code' => $type->code,
                    'color' => $type->color,
                    'is_paid' => $type->is_paid,
                    'quota' => $type->annual_quota,
                    'used' => $b['used'],
                    'pending' => $b['pending'],
                    'remaining' => $type->isCapped() ? $b['remaining'] : null,
                ];
            })->all();
    }

    /** Blocking appointments a practitioner has during the leave (to warn before approving). */
    public function conflictingAppointments(LeaveRequest $leave): int
    {
        $tz = app(TenantContext::class)->require()->timezone;

        return Appointment::query()
            ->where('practitioner_id', $leave->user_id)
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->where('starts_at', '>=', CarbonImmutable::parse($leave->start_date->toDateString(), $tz)->utc())
            ->where('starts_at', '<', CarbonImmutable::parse($leave->end_date->toDateString(), $tz)->addDay()->utc())
            ->count();
    }

    /** @return array{used: float, pending: float, remaining: float} */
    private function balanceFor(int $userId, LeaveType $type, int $year): array
    {
        $rows = LeaveRequest::query()
            ->where('user_id', $userId)
            ->where('leave_type_id', $type->id)
            ->whereIn('status', LeaveStatus::holdingValues())
            ->whereYear('start_date', $year)
            ->selectRaw('status, SUM(days) AS days')
            ->groupBy('status')
            ->pluck('days', 'status');

        $used = (float) ($rows[LeaveStatus::Approved->value] ?? 0);
        $pending = (float) ($rows[LeaveStatus::Pending->value] ?? 0);

        return ['used' => $used, 'pending' => $pending, 'remaining' => max(0.0, $type->annual_quota - $used - $pending)];
    }

    private function decide(LeaveRequest $leave, User $actor, LeaveStatus $to, ?string $note): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $actor, $to, $note) {
            $locked = LeaveRequest::query()->lockForUpdate()->findOrFail($leave->id);

            if ($locked->status !== LeaveStatus::Pending) {
                throw new DomainRuleViolation('This request has already been decided.', 'invalid_state');
            }
            if ($locked->user_id === $actor->id && ! $actor->hasRole('owner')) {
                throw new DomainRuleViolation('You cannot decide your own leave request.', 'self_approval');
            }

            $locked->forceFill([
                'status' => $to,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            return $locked;
        });
    }
}
