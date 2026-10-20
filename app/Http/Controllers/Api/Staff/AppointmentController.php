<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\RoomResource;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\BookingRequest;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\SeriesRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @tags Staff · Appointments
 */
class AppointmentController extends Controller
{
    private const RELATIONS = ['patient', 'practitioner', 'room', 'service'];

    public function __construct(private readonly BookingService $booking) {}

    /** Appointments in a local date range (max 62 days) for the calendar and lists. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Appointment::class);

        $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer'],
            'practitioner_ids' => ['nullable', 'array'],
            'practitioner_ids.*' => ['integer'],
            'patient_id' => ['nullable', 'integer'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::enum(AppointmentStatus::class)],
        ]);

        $tz = $request->user()->tenant->timezone;
        $from = CarbonImmutable::parse($request->input('from'), $tz);
        $to = CarbonImmutable::parse($request->input('to'), $tz)->addDay();
        $maxDays = $request->filled('patient_id') ? 731 : 62; // a single patient's history may span longer
        abort_if($from->diffInDays($to) > $maxDays, 422, "Date range is limited to {$maxDays} days.");

        $appointments = Appointment::query()
            ->visibleTo($request->user())
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->utc())
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('practitioner_ids'), fn ($q) => $q->whereIn('practitioner_id', $request->input('practitioner_ids')))
            ->when($request->filled('patient_id'), fn ($q) => $q->where('patient_id', $request->integer('patient_id')))
            ->when($request->filled('statuses'), fn ($q) => $q->whereIn('status', $request->input('statuses')))
            ->with(self::RELATIONS)
            ->orderBy('starts_at')
            ->limit(2000)
            ->get();

        return AppointmentResource::collection($appointments);
    }

    /**
     * Book an appointment, or a weekly series when "recurrence" is present.
     * Returns 409 with "conflicts" when the practitioner, room or patient is busy.
     */
    public function store(AppointmentRequest $request): JsonResponse
    {
        Gate::authorize('create', Appointment::class);
        $data = $request->validated();
        abort_unless($request->user()->canAccessBranch((int) $data['branch_id']), 403);

        $bookingRequest = $this->toBookingRequest($data);

        if (! empty($data['recurrence'])) {
            $r = $data['recurrence'];
            $result = $this->booking->bookSeries(
                $bookingRequest,
                new SeriesRule(
                    weekdays: array_map('intval', $r['weekdays']),
                    interval: (int) ($r['interval'] ?? 1),
                    count: isset($r['count']) ? (int) $r['count'] : null,
                    until: isset($r['until']) ? CarbonImmutable::parse($r['until']) : null,
                ),
                $data['on_conflict'] ?? 'fail',
                $request->user()->id,
            );

            $created = collect($result['created'])->each->load(self::RELATIONS);

            return response()->json([
                'data' => AppointmentResource::collection($created),
                'series_id' => $result['series']->id,
                'skipped' => $result['skipped'],
            ], 201);
        }

        $appointment = $this->booking->book($bookingRequest, $request->user()->id);

        return (new AppointmentResource($appointment->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        Gate::authorize('view', $appointment);

        return new AppointmentResource($appointment->load(self::RELATIONS));
    }

    /** Reschedule or reassign (practitioner, room, time) a scheduled/confirmed appointment. */
    public function update(AppointmentRequest $request, Appointment $appointment): AppointmentResource
    {
        Gate::authorize('update', $appointment);
        $data = $request->validated();

        $timeFields = ['practitioner_id', 'branch_id', 'room_id', 'service_id', 'type', 'local_start', 'duration_minutes', 'travel_buffer_minutes', 'home_visit_address'];

        if (array_intersect(array_keys($data), $timeFields) === []) {
            $appointment->update(array_intersect_key($data, array_flip(['notes'])));

            return new AppointmentResource($appointment->load(self::RELATIONS));
        }

        $merged = array_merge([
            'patient_id' => $appointment->patient_id,
            'practitioner_id' => $appointment->practitioner_id,
            'branch_id' => $appointment->branch_id,
            'room_id' => $appointment->room_id,
            'service_id' => $appointment->service_id,
            'type' => $appointment->type->value,
            'local_start' => $appointment->local_starts_at->format('Y-m-d\TH:i'),
            'duration_minutes' => $appointment->durationMinutes(),
            'travel_buffer_minutes' => $appointment->travel_buffer_minutes,
            'home_visit_address' => $appointment->home_visit_address,
            'notes' => $appointment->notes,
        ], $data, ['ignore_appointment_id' => $appointment->id]);

        abort_unless($request->user()->canAccessBranch((int) $merged['branch_id']), 403);

        $updated = $this->booking->reschedule($appointment, $this->toBookingRequest($merged));

        return new AppointmentResource($updated->load(self::RELATIONS));
    }

    /** Move through the visit workflow: confirm, check in, start, complete, cancel, no-show. */
    public function transition(Request $request, Appointment $appointment): AppointmentResource
    {
        Gate::authorize('update', $appointment);

        $data = $request->validate([
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
            'reason' => ['nullable', 'required_if:status,cancelled', 'string', 'max:255'],
        ]);

        $updated = $this->booking->transition($appointment, AppointmentStatus::from($data['status']), $data['reason'] ?? null, $request->user()->id);

        return new AppointmentResource($updated->load(self::RELATIONS));
    }

    /** Create a draft invoice for this appointment's service. */
    public function invoice(Request $request, Appointment $appointment, InvoiceService $invoices): JsonResponse
    {
        Gate::authorize('view', $appointment);
        Gate::authorize('create', Invoice::class);

        $alreadyBilled = InvoiceItem::query()
            ->where('appointment_id', $appointment->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'void'))
            ->exists();

        if ($alreadyBilled) {
            throw new DomainRuleViolation('This appointment is already on an invoice.', 'already_invoiced');
        }

        $service = $appointment->service_id ? Service::query()->find($appointment->service_id) : null;

        $invoice = $invoices->createDraft([
            'patient_id' => $appointment->patient_id,
            'branch_id' => $appointment->branch_id,
            'items' => [[
                'service_id' => $service?->id,
                'appointment_id' => $appointment->id,
                'description' => ($service?->name ?? 'Physiotherapy session').' · '.$appointment->local_starts_at->format('d M Y'),
                'unit_price_minor' => $service?->price_minor ?? 0,
            ]],
        ], $request->user()->id);

        return (new InvoiceResource($invoice->load(['items', 'patient'])))->response()->setStatusCode(201);
    }

    /** Free slots for a practitioner on a local date. */
    public function availability(Request $request, AvailabilityService $availability): JsonResponse
    {
        Gate::authorize('viewAny', Appointment::class);

        $data = $request->validate([
            'practitioner_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'service_id' => ['nullable', 'integer'],
            'duration_minutes' => ['nullable', 'integer', 'min:10', 'max:480'],
        ]);

        $branch = Branch::query()->findOrFail($data['branch_id']);
        $duration = $data['duration_minutes'] ?? (isset($data['service_id']) ? Service::query()->findOrFail($data['service_id'])->duration_minutes : 45);
        $tz = $branch->effectiveTimezone();

        return response()->json(['data' => $availability->slots(
            (int) $data['practitioner_id'], $branch->id, CarbonImmutable::parse($data['date'], $tz), $tz, $duration,
        )]);
    }

    /** Practitioners (with working hours) and rooms of a branch, for calendar columns. */
    public function resources(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Appointment::class);
        $branchId = $request->validate(['branch_id' => ['required', 'integer']])['branch_id'];
        abort_unless($request->user()->canAccessBranch((int) $branchId), 403);

        $practitioners = User::query()
            ->where('is_practitioner', true)
            ->where('is_active', true)
            ->whereHas('branches', fn ($q) => $q->whereKey($branchId))
            ->with(['workingHours' => fn ($q) => $q->where('branch_id', $branchId)->orderBy('weekday')->orderBy('start_time')])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => [
            'practitioners' => $practitioners->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'title' => $u->title,
                'color' => $u->color,
                'working_hours' => $u->workingHours->map(fn ($wh) => [
                    'weekday' => $wh->weekday,
                    'start_time' => substr($wh->start_time, 0, 5),
                    'end_time' => substr($wh->end_time, 0, 5),
                ]),
            ]),
            'rooms' => RoomResource::collection(Room::query()->where('branch_id', $branchId)->where('is_active', true)->orderBy('name')->get()),
        ]]);
    }

    private function toBookingRequest(array $data): BookingRequest
    {
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $tz = $branch->effectiveTimezone();
        $service = ! empty($data['service_id']) ? Service::query()->findOrFail($data['service_id']) : null;
        $type = AppointmentType::from($data['type'] ?? AppointmentType::Clinic->value);

        return new BookingRequest(
            patientId: (int) $data['patient_id'],
            practitionerId: (int) $data['practitioner_id'],
            branchId: $branch->id,
            localStart: CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['local_start'], $tz)->startOfMinute(),
            durationMinutes: (int) ($data['duration_minutes'] ?? $service?->duration_minutes ?? 45),
            timezone: $tz,
            roomId: $type === AppointmentType::HomeVisit ? null : (isset($data['room_id']) ? (int) $data['room_id'] : null),
            serviceId: $service?->id,
            type: $type,
            travelBufferMinutes: (int) ($data['travel_buffer_minutes'] ?? ($type === AppointmentType::HomeVisit ? 30 : 0)),
            homeVisitAddress: $type === AppointmentType::HomeVisit ? ($data['home_visit_address'] ?? null) : null,
            notes: $data['notes'] ?? null,
            bookedVia: 'staff',
            allowOutsideHours: (bool) ($data['allow_outside_hours'] ?? false),
            ignoreAppointmentId: $data['ignore_appointment_id'] ?? null,
        );
    }
}
