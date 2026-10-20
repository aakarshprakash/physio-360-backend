<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\LeaveCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Staff attendance. Everyone clocks themselves in and out and sees their own
 * register; "attendance.manage" sees the whole team and records corrections.
 *
 * @tags Staff · Attendance
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /** The signed-in user's day: clock state, worked minutes, leave. */
    public function today(Request $request, LeaveCalendar $leave): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->todayPayload($user, $this->attendance->todayFor($user), $leave->coverage($user->id, $this->attendance->today()))]);
    }

    public function checkIn(Request $request, LeaveCalendar $leave): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $request->user()?->tenant_id)]]);
        $record = $this->attendance->checkIn($request->user(), $data['branch_id'] ?? null);

        return response()->json(['data' => $this->todayPayload($request->user(), $record, $leave->coverage($request->user()->id, $this->attendance->today()))]);
    }

    public function checkOut(Request $request, LeaveCalendar $leave): JsonResponse
    {
        $record = $this->attendance->checkOut($request->user());

        return response()->json(['data' => $this->todayPayload($request->user(), $record, $leave->coverage($request->user()->id, $this->attendance->today()))]);
    }

    /** Month register. Without attendance.manage only the caller's own row is returned. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $viewer = $request->user();
        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'] ?? CarbonImmutable::now($viewer->tenant->timezone)->format('Y-m'), $viewer->tenant->timezone);
        $manager = $viewer->can('attendance.manage');

        $users = $manager
            ? User::query()->where('is_active', true)->when(isset($data['user_id']), fn ($q) => $q->whereKey($data['user_id']))->orderBy('name')->get()
            : collect([$viewer]);

        return response()->json(['data' => $this->attendance->register($month, $users), 'can_manage' => $manager]);
    }

    /** Record or correct a day for a team member. */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('attendance.manage');
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('tenant_id', $request->user()?->tenant_id)],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $record = $this->attendance->record($data, $request->user()->id);

        return response()->json(['data' => [
            'id' => $record->id,
            'user_id' => $record->user_id,
            'work_date' => $record->work_date->toDateString(),
            'status' => $record->status->value,
        ]]);
    }

    private function todayPayload(User $user, ?AttendanceRecord $record, ?string $leave): array
    {
        $tz = $this->attendance->timezone();
        $minutes = $record?->minutes_worked
            ?? ($record?->check_in_at && ! $record->check_out_at ? (int) $record->check_in_at->diffInMinutes(now()) : null);

        return [
            'date' => $this->attendance->today()->toDateString(),
            'status' => $record?->status->value,
            'check_in' => $record?->check_in_at?->timezone($tz)->format('H:i'),
            'check_out' => $record?->check_out_at?->timezone($tz)->format('H:i'),
            'check_in_at' => $record?->check_in_at?->utc()->toIso8601ZuluString(),
            'minutes_worked' => $minutes,
            'is_late' => (bool) $record?->is_late,
            'on_leave' => $leave,
            'can_check_in' => ! $record?->check_in_at,
            'can_check_out' => (bool) ($record?->check_in_at && ! $record->check_out_at),
        ];
    }
}
