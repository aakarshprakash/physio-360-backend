<?php

use App\Enums\Role;
use App\Models\AttendanceRecord;
use App\Models\LeaveType;
use App\Models\Payslip;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = clinic();
    $this->therapist = staff($this->tenant, Role::Therapist);
    $this->desk = staff($this->tenant, Role::Receptionist);
    $this->accountant = staff($this->tenant, Role::Accountant);
    $this->types = inTenant($this->tenant, fn () => LeaveType::query()->pluck('id', 'code'));
    $this->tz = 'Asia/Kolkata';
});

function leaveFor(object $t, $user, string $code, string $from, ?string $to = null, array $extra = [])
{
    return actingAsStaff($user)->postJson('/api/leave-requests', array_merge([
        'leave_type_id' => $t->types[$code], 'start_date' => $from, 'end_date' => $to ?? $from,
    ], $extra));
}

/** A Monday at least a week ahead, in clinic time. */
function nextMonday(int $weeksAhead = 1): CarbonImmutable
{
    return CarbonImmutable::now('Asia/Kolkata')->startOfDay()->next(CarbonImmutable::MONDAY)->addWeeks($weeksAhead - 1);
}

// ---- Attendance -----------------------------------------------------------

it('clocks staff in and out once a day', function () {
    $this->travelTo(CarbonImmutable::parse(nextMonday()->toDateString().' 09:05', $this->tz));

    actingAsStaff($this->therapist)->getJson('/api/attendance/today')->assertOk()->assertJsonPath('data.can_check_in', true);
    actingAsStaff($this->therapist)->postJson('/api/attendance/check-in')->assertOk()
        ->assertJsonPath('data.check_in', '09:05')->assertJsonPath('data.is_late', false)->assertJsonPath('data.can_check_out', true);
    actingAsStaff($this->therapist)->postJson('/api/attendance/check-in')->assertUnprocessable()->assertJsonPath('code', 'already_checked_in');

    $this->travel(8)->hours();
    actingAsStaff($this->therapist)->postJson('/api/attendance/check-out')->assertOk()
        ->assertJsonPath('data.check_out', '17:05')->assertJsonPath('data.minutes_worked', 480)->assertJsonPath('data.can_check_out', false);
    actingAsStaff($this->desk)->postJson('/api/attendance/check-out')->assertUnprocessable()->assertJsonPath('code', 'not_checked_in');
});

it('flags late arrival against the first working interval plus grace', function () {
    $this->travelTo(CarbonImmutable::parse(nextMonday()->toDateString().' 09:25', $this->tz)); // shift starts 09:00, grace 10 min
    actingAsStaff($this->therapist)->postJson('/api/attendance/check-in')->assertOk()->assertJsonPath('data.is_late', true);
});

it('lets managers record days and shows a merged monthly register', function () {
    $monday = nextMonday();
    $this->travelTo(CarbonImmutable::parse($monday->addDays(3)->toDateString().' 20:00', $this->tz));

    actingAsStaff($this->desk)->postJson('/api/attendance', ['user_id' => $this->therapist->id, 'work_date' => $monday->toDateString(), 'status' => 'present'])->assertForbidden();
    actingAsStaff($this->owner)->postJson('/api/attendance', [
        'user_id' => $this->therapist->id, 'work_date' => $monday->toDateString(), 'status' => 'present', 'check_in' => '09:00', 'check_out' => '18:30',
    ])->assertOk();
    actingAsStaff($this->owner)->postJson('/api/attendance', ['user_id' => $this->therapist->id, 'work_date' => $monday->addDay()->toDateString(), 'status' => 'absent'])->assertOk();
    actingAsStaff($this->owner)->postJson('/api/attendance', ['user_id' => $this->therapist->id, 'work_date' => $monday->addWeek()->toDateString(), 'status' => 'present'])
        ->assertUnprocessable()->assertJsonPath('code', 'future_date');

    $register = actingAsStaff($this->owner)->getJson('/api/attendance?month='.$monday->format('Y-m'))->assertOk()->json('data');
    $row = collect($register['rows'])->firstWhere('user.id', $this->therapist->id);
    expect($row['cells'][$monday->toDateString()])->toMatchArray(['status' => 'present', 'check_in' => '09:00', 'minutes' => 570])
        ->and($row['cells'][$monday->addDay()->toDateString()]['status'])->toBe('absent')
        ->and($row['cells'][$monday->subDay()->toDateString()]['status'])->toBe('off'); // Sunday

    // Without attendance.manage only your own row comes back.
    $own = actingAsStaff($this->therapist)->getJson('/api/attendance?month='.$monday->format('Y-m'))->json('data.rows');
    expect($own)->toHaveCount(1)->and($own[0]['user']['id'])->toBe($this->therapist->id);
});

// ---- Leave ----------------------------------------------------------------

it('counts leave in working days, skipping weekly offs and holidays', function () {
    $monday = nextMonday(2);
    actingAsStaff($this->owner)->putJson('/api/settings/clinic', ['settings' => ['holidays' => [['date' => $monday->addDay()->toDateString(), 'name' => 'Onam']]]])->assertOk();

    // Mon..next Mon = 8 dates, minus Sunday, minus the holiday = 6 days.
    leaveFor($this, $this->therapist, 'CL', $monday->toDateString(), $monday->addWeek()->toDateString())->assertCreated()
        ->assertJsonPath('data.days', 6)->assertJsonPath('data.status', 'pending');

    leaveFor($this, $this->therapist, 'SL', $monday->addDays(2)->toDateString())->assertUnprocessable()->assertJsonPath('code', 'overlapping_leave');
    leaveFor($this, $this->therapist, 'SL', $monday->subDay()->toDateString())->assertUnprocessable()->assertJsonPath('code', 'no_working_days'); // Sunday
    leaveFor($this, $this->desk, 'CL', $monday->toDateString(), $monday->addDays(30)->toDateString())->assertUnprocessable()->assertJsonPath('code', 'insufficient_balance');
    leaveFor($this, $this->desk, 'CL', $monday->toDateString(), null, ['half_day' => 'am'])->assertCreated()->assertJsonPath('data.days', 0.5);

    $balance = collect(actingAsStaff($this->therapist)->getJson('/api/leave-balances')->assertOk()->json('data'))->firstWhere('code', 'CL');
    expect($balance)->toMatchArray(['quota' => 12, 'pending' => 6, 'used' => 0, 'remaining' => 6]);
});

it('routes approval to approvers and blocks booking during approved leave', function () {
    $monday = nextMonday(2);
    $patient = patient($this->tenant);
    $id = leaveFor($this, $this->therapist, 'CL', $monday->toDateString())->json('data.id');

    // An existing booking on that day is flagged before approving.
    actingAsStaff($this->owner)->postJson('/api/appointments', [
        'patient_id' => $patient->id, 'practitioner_id' => $this->therapist->id, 'branch_id' => $this->branch->id,
        'service_id' => service($this->tenant)->id, 'local_start' => $monday->toDateString().'T10:00',
    ])->assertCreated();
    actingAsStaff($this->owner)->getJson('/api/leave-requests')->assertJsonPath('data.0.conflicting_appointments', 1)->assertJsonPath('pending_approvals', 1);

    actingAsStaff($this->therapist)->postJson("/api/leave-requests/{$id}/approve")->assertForbidden();
    actingAsStaff($this->owner)->postJson("/api/leave-requests/{$id}/reject")->assertJsonValidationErrors('note');
    actingAsStaff($this->owner)->postJson("/api/leave-requests/{$id}/approve", ['note' => 'Enjoy'])->assertOk()->assertJsonPath('data.status', 'approved');
    actingAsStaff($this->owner)->postJson("/api/leave-requests/{$id}/approve")->assertUnprocessable()->assertJsonPath('code', 'invalid_state');

    // No slots that day, and booking is refused even with the outside-hours override.
    actingAsStaff($this->owner)->getJson('/api/availability?'.http_build_query([
        'practitioner_id' => $this->therapist->id, 'branch_id' => $this->branch->id, 'date' => $monday->toDateString(), 'duration_minutes' => 45,
    ]))->assertOk()->assertJsonCount(0, 'data');
    actingAsStaff($this->owner)->postJson('/api/appointments', [
        'patient_id' => $patient->id, 'practitioner_id' => $this->therapist->id, 'branch_id' => $this->branch->id,
        'service_id' => service($this->tenant)->id, 'local_start' => $monday->toDateString().'T15:00', 'allow_outside_hours' => true,
    ])->assertUnprocessable()->assertJsonPath('code', 'practitioner_on_leave');

    // Cancelling the leave frees the day again.
    actingAsStaff($this->therapist)->postJson("/api/leave-requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(actingAsStaff($this->owner)->getJson('/api/availability?'.http_build_query([
        'practitioner_id' => $this->therapist->id, 'branch_id' => $this->branch->id, 'date' => $monday->toDateString(), 'duration_minutes' => 45,
    ]))->json('data'))->not->toBeEmpty();
});

it('keeps the afternoon bookable during morning half-day leave', function () {
    $monday = nextMonday(2);
    $id = leaveFor($this, $this->therapist, 'CL', $monday->toDateString(), null, ['half_day' => 'am'])->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/leave-requests/{$id}/approve")->assertOk();

    $slots = collect(actingAsStaff($this->owner)->getJson('/api/availability?'.http_build_query([
        'practitioner_id' => $this->therapist->id, 'branch_id' => $this->branch->id, 'date' => $monday->toDateString(), 'duration_minutes' => 45,
    ]))->json('data'))->pluck('local_start')->map(fn ($s) => substr($s, 11));

    expect($slots)->toContain('14:00')->not->toContain('09:00', '12:00');
});

it('stops people approving their own leave unless they own the clinic', function () {
    $admin = staff($this->tenant, Role::Admin);
    $id = leaveFor($this, $admin, 'CL', nextMonday(2)->toDateString())->json('data.id');
    actingAsStaff($admin)->postJson("/api/leave-requests/{$id}/approve")->assertUnprocessable()->assertJsonPath('code', 'self_approval');

    $own = leaveFor($this, $this->owner, 'CL', nextMonday(3)->toDateString())->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/leave-requests/{$own}/approve")->assertOk();
});

// ---- Payroll --------------------------------------------------------------

it('runs payroll with loss-of-pay proration, adjustments and a frozen final state', function () {
    $month = nextMonday()->startOfMonth()->addMonth();
    $this->travelTo($month->endOfMonth()->setTime(20, 0));

    $salary = ['basic_minor' => 30_000_00, 'allowances' => [['name' => 'HRA', 'amount_minor' => 9_000_00]], 'deductions' => [['name' => 'PF', 'amount_minor' => 1_800_00]], 'effective_from' => $month->subYear()->toDateString()];
    actingAsStaff($this->therapist)->putJson("/api/payroll/salaries/{$this->therapist->id}", $salary)->assertForbidden();
    actingAsStaff($this->accountant)->putJson("/api/payroll/salaries/{$this->therapist->id}", $salary)->assertOk()->assertJsonPath('data.monthly_gross_minor', 39_000_00);

    // One unpaid leave day and one absence → 2 LOP days.
    $workDays = collect(range(0, $month->daysInMonth - 1))->map(fn ($d) => $month->addDays($d))->reject(fn ($d) => $d->isSunday())->values();
    $lop = leaveFor($this, $this->therapist, 'LOP', $workDays[3]->toDateString())->json('data.id');
    actingAsStaff($this->owner)->postJson("/api/leave-requests/{$lop}/approve")->assertOk();
    actingAsStaff($this->owner)->postJson('/api/attendance', ['user_id' => $this->therapist->id, 'work_date' => $workDays[8]->toDateString(), 'status' => 'absent'])->assertOk();

    $run = actingAsStaff($this->accountant)->postJson('/api/payroll/runs', ['period' => $month->format('Y-m')])->assertCreated()->json('data');
    $working = $workDays->count();
    $slip = collect($run['payslips'])->firstWhere('user.id', $this->therapist->id);
    $basic = (int) round(30_000_00 * ($working - 2) / $working);
    $hra = (int) round(9_000_00 * ($working - 2) / $working);

    expect($run['working_days'])->toBe($working)
        ->and($slip['lop_days'])->toEqual(2)
        ->and($slip['earnings'])->toBe([['name' => 'Basic', 'amount_minor' => $basic], ['name' => 'HRA', 'amount_minor' => $hra]])
        ->and($slip['net_minor'])->toBe($basic + $hra - 1_800_00);
    actingAsStaff($this->accountant)->postJson('/api/payroll/runs', ['period' => $month->format('Y-m')])->assertUnprocessable()->assertJsonPath('code', 'payroll_exists');

    actingAsStaff($this->accountant)->putJson("/api/payslips/{$slip['id']}", [
        'earnings' => [...$slip['earnings'], ['name' => 'Incentive', 'amount_minor' => 2_500_00]],
        'deductions' => $slip['deductions'], 'notes' => 'Weekend camp',
    ])->assertOk()->assertJsonPath('data.gross_minor', $basic + $hra + 2_500_00);

    // Not visible to the employee until finalized.
    actingAsStaff($this->therapist)->getJson('/api/payroll/my-payslips')->assertOk()->assertJsonCount(0, 'data');
    actingAsStaff($this->therapist)->get("/api/payslips/{$slip['id']}/pdf")->assertForbidden();

    actingAsStaff($this->accountant)->postJson("/api/payroll/runs/{$run['id']}/finalize")->assertOk()->assertJsonPath('data.status', 'finalized');
    actingAsStaff($this->accountant)->putJson("/api/payslips/{$slip['id']}", ['earnings' => $slip['earnings'], 'deductions' => []])->assertUnprocessable()->assertJsonPath('code', 'payroll_finalized');
    expect(fn () => inTenant($this->tenant, fn () => DB::table('payslips')->where('id', $slip['id'])->update(['net_minor' => 1])))->toThrow(QueryException::class);

    actingAsStaff($this->therapist)->getJson('/api/payroll/my-payslips')->assertJsonCount(1, 'data')->assertJsonPath('data.0.net_minor', $basic + $hra + 2_500_00 - 1_800_00);
    expect(actingAsStaff($this->therapist)->get("/api/payslips/{$slip['id']}/pdf?lang=ml")->assertOk()->getContent())->toStartWith('%PDF');
    actingAsStaff($this->desk)->get("/api/payslips/{$slip['id']}/pdf")->assertForbidden();

    actingAsStaff($this->accountant)->postJson("/api/payroll/runs/{$run['id']}/mark-paid")->assertOk()->assertJsonPath('data.status', 'paid');
    actingAsStaff($this->accountant)->deleteJson("/api/payroll/runs/{$run['id']}")->assertUnprocessable();
    expect(inTenant($this->tenant, fn () => Payslip::query()->count()))->toBe(1);
});

it('keeps payroll to payroll managers', function () {
    actingAsStaff($this->therapist)->getJson('/api/payroll/runs')->assertForbidden();
    actingAsStaff($this->desk)->getJson('/api/payroll/salaries')->assertForbidden();
    actingAsStaff($this->accountant)->getJson('/api/payroll/salaries')->assertOk();
    expect(inTenant($this->tenant, fn () => AttendanceRecord::query()->count()))->toBe(0);
});
