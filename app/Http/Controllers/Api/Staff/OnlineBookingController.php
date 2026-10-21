<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\OnlineBooking;
use App\Models\Patient;
use App\Services\Scheduling\OnlineBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Front-desk inbox for requests from the public booking page.
 *
 * @tags Staff · Online booking
 */
class OnlineBookingController extends Controller
{
    public function __construct(private readonly OnlineBookingService $online) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('appointments.manage');
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...OnlineBooking::STATUSES, 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = $data['status'] ?? 'pending';
        $user = $request->user();

        $page = OnlineBooking::query()
            ->visibleTo($user)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['branch', 'service', 'practitioner', 'patient', 'decider:id,name'])
            ->when($status === 'pending', fn ($q) => $q->orderBy('requested_start'), fn ($q) => $q->latest('id'))
            ->paginate(25);

        $counts = OnlineBooking::query()->visibleTo($user)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'data' => collect($page->items())->map(fn (OnlineBooking $b) => $this->present($b)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'counts' => collect(OnlineBooking::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            ],
        ]);
    }

    /** Existing patients this request could belong to (same phone number). */
    public function matches(OnlineBooking $booking): JsonResponse
    {
        Gate::authorize('appointments.manage');

        return response()->json(['data' => $this->online->matchingPatients($booking)->map(fn (Patient $p) => [
            'id' => $p->id, 'full_name' => $p->full_name, 'mrn' => $p->mrn, 'phone' => $p->phone,
        ])]);
    }

    public function confirm(Request $request, OnlineBooking $booking): JsonResponse
    {
        Gate::authorize('appointments.manage');
        abort_unless($request->user()->canAccessBranch($booking->branch_id), 404);
        $data = $request->validate([
            'patient_id' => ['nullable', 'integer', Rule::exists('patients', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'practitioner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'local_start' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'new_patient' => ['sometimes', 'boolean'],
        ]);

        $booking = $this->online->confirm($booking, $request->user(), $data);

        return response()->json(['data' => $this->present($booking->load(['branch', 'service', 'practitioner', 'patient', 'decider:id,name']))]);
    }

    public function decline(Request $request, OnlineBooking $booking): JsonResponse
    {
        Gate::authorize('appointments.manage');
        abort_unless($request->user()->canAccessBranch($booking->branch_id), 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $booking = $this->online->decline($booking, $request->user(), $data['note'] ?? null);

        return response()->json(['data' => $this->present($booking->load(['branch', 'service', 'practitioner', 'patient', 'decider:id,name']))]);
    }

    private function present(OnlineBooking $b): array
    {
        $tz = $b->branch->effectiveTimezone();

        return [
            'id' => $b->id,
            'reference' => $b->reference,
            'status' => $b->status,
            'source' => $b->source,
            'starts_at' => $b->requested_start->utc()->toIso8601ZuluString(),
            'local_start' => $b->requested_start->timezone($tz)->format('Y-m-d\TH:i'),
            'duration_minutes' => $b->duration_minutes,
            'name' => $b->name,
            'phone' => $b->phone,
            'email' => $b->email,
            'gender' => $b->gender,
            'age' => $b->age,
            'message' => $b->message,
            'branch' => ['id' => $b->branch->id, 'name' => $b->branch->name],
            'service' => $b->service ? ['id' => $b->service->id, 'name' => $b->service->name] : null,
            'practitioner' => $b->practitioner ? ['id' => $b->practitioner->id, 'name' => $b->practitioner->name, 'color' => $b->practitioner->color] : null,
            'patient' => $b->patient ? ['id' => $b->patient->id, 'full_name' => $b->patient->full_name, 'mrn' => $b->patient->mrn] : null,
            'appointment_id' => $b->appointment_id,
            'decided_by' => $b->decider?->name,
            'decided_at' => $b->decided_at?->utc()->toIso8601ZuluString(),
            'decision_note' => $b->decision_note,
            'created_at' => $b->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
