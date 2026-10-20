<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Hr\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Leave requests and balances. Everyone requests their own leave; approvers
 * ("leave.approve") see and decide the team's requests and may file on
 * someone's behalf.
 *
 * @tags Staff · Leave
 */
class LeaveController extends Controller
{
    private const RELATIONS = ['user:id,name,color,is_practitioner', 'type:id,name,code,color,is_paid', 'decider:id,name'];

    public function __construct(private readonly LeaveService $leave) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(LeaveStatus::class)],
            'user_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'scope' => ['nullable', Rule::in(['mine', 'team'])],
        ]);

        $viewer = $request->user();
        $team = ($data['scope'] ?? 'team') === 'team' && $viewer->can('leave.approve');
        $year = $data['year'] ?? CarbonImmutable::now($viewer->tenant->timezone)->year;

        $page = LeaveRequest::query()
            ->when(! $team, fn ($q) => $q->where('user_id', $viewer->id))
            ->when($team && isset($data['user_id']), fn ($q) => $q->where('user_id', $data['user_id']))
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->whereYear('start_date', $year)
            ->with(self::RELATIONS)
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('start_date')
            ->paginate(30);

        // Approvers need to know whether approving would strand booked patients.
        if ($team) {
            $page->getCollection()->each(function (LeaveRequest $l) {
                if ($l->status === LeaveStatus::Pending && $l->user->is_practitioner) {
                    $l->conflicting_appointments = $this->leave->conflictingAppointments($l);
                }
            });
        }

        return LeaveRequestResource::collection($page)->additional([
            'balances' => $this->leave->balances($viewer->id, $year),
            'pending_approvals' => $viewer->can('leave.approve') ? LeaveRequest::query()->where('status', LeaveStatus::Pending)->where('user_id', '!=', $viewer->id)->count() : 0,
            'can_approve' => $viewer->can('leave.approve'),
            'year' => $year,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('tenant_id', $tenantId)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'half_day' => ['nullable', Rule::in(['am', 'pm'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $request->user();
        $forUser = $actor;
        if (isset($data['user_id']) && $data['user_id'] !== $actor->id) {
            Gate::authorize('leave.approve');
            $forUser = User::query()->findOrFail($data['user_id']);
        }

        $leave = $this->leave->request($forUser, $data, $actor->id);

        return (new LeaveRequestResource($leave->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('leave.approve');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return new LeaveRequestResource($this->leave->approve($leaveRequest, $request->user(), $data['note'] ?? null)->load(self::RELATIONS));
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('leave.approve');
        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);

        return new LeaveRequestResource($this->leave->reject($leaveRequest, $request->user(), $data['note'])->load(self::RELATIONS));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        abort_unless($leaveRequest->user_id === $request->user()->id || $request->user()->can('leave.approve'), 403);

        return new LeaveRequestResource($this->leave->cancel($leaveRequest, $request->user())->load(self::RELATIONS));
    }

    /** Balances for one person (approvers may look up anyone). */
    public function balances(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);
        $userId = $data['user_id'] ?? $request->user()->id;
        abort_unless($userId === $request->user()->id || $request->user()->can('leave.approve'), 403);

        return response()->json(['data' => $this->leave->balances($userId, $data['year'] ?? CarbonImmutable::now($request->user()->tenant->timezone)->year)]);
    }

    // ---- Leave types (settings) -------------------------------------------

    public function types(): JsonResponse
    {
        return response()->json(['data' => LeaveType::query()->orderBy('name')->get()->map(fn (LeaveType $t) => $this->typePayload($t))]);
    }

    public function storeType(Request $request): JsonResponse
    {
        Gate::authorize('settings.manage');
        $type = LeaveType::query()->create($this->typeData($request));

        return response()->json(['data' => $this->typePayload($type)], 201);
    }

    public function updateType(Request $request, LeaveType $leaveType): JsonResponse
    {
        Gate::authorize('settings.manage');
        $leaveType->update($this->typeData($request, $leaveType));

        return response()->json(['data' => $this->typePayload($leaveType)]);
    }

    private function typeData(Request $request, ?LeaveType $type = null): array
    {
        $required = $type ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:96'],
            'code' => [$required, 'alpha_num:ascii', 'max:12', Rule::unique('leave_types', 'code')->where('tenant_id', $request->user()?->tenant_id)->ignore($type?->id)],
            'annual_quota' => ['sometimes', 'numeric', 'between:0,365'],
            'is_paid' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function typePayload(LeaveType $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'annual_quota' => $t->annual_quota, 'is_paid' => $t->is_paid, 'color' => $t->color, 'is_active' => $t->is_active];
    }
}
