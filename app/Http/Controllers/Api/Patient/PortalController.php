<?php

namespace App\Http\Controllers\Api\Patient;

use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Api\Staff\InvoiceController;
use App\Http\Controllers\Api\Staff\TreatmentPlanController;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\BranchResource;
use App\Http\Resources\ConsentSignatureResource;
use App\Http\Resources\ConsentTemplateResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\ProgressEntryResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\TenantResource;
use App\Http\Resources\TreatmentPlanResource;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ConsentSignature;
use App\Models\ConsentTemplate;
use App\Models\ExerciseLog;
use App\Models\Feedback;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\ProgressEntry;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanExercise;
use App\Models\User;
use App\Services\Clinical\ConsentService;
use App\Services\Payments\RazorpayGateway;
use App\Services\Pdf\PdfRenderer;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\BookingRequest;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\SlotConflict;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything here is implicitly scoped to the signed-in patient; IDs of other
 * patients' records return 404.
 *
 * @tags Patient app
 */
class PortalController extends Controller
{
    private const MAX_UPCOMING_PORTAL_BOOKINGS = 4;

    public function __construct(private readonly TenantContext $tenants) {}

    public static function profile(Patient $patient): array
    {
        $tenant = Tenant::query()->with('currency')->findOrFail($patient->tenant_id);

        return [
            'patient' => [
                'id' => $patient->id,
                'mrn' => $patient->mrn,
                'first_name' => $patient->first_name,
                'full_name' => $patient->full_name,
                'email' => $patient->email,
                'phone' => $patient->phone,
                'preferred_language' => $patient->preferred_language,
            ],
            'clinic' => new TenantResource($tenant),
        ];
    }

    public function me(): JsonResponse
    {
        return response()->json(['data' => self::profile($this->patient())]);
    }

    /** Branches, services and practitioners the patient can book. */
    public function catalog(): JsonResponse
    {
        $tenant = $this->tenants->require();

        return response()->json(['data' => [
            'booking_enabled' => (bool) $tenant->setting('portal_booking_enabled', true),
            'branches' => BranchResource::collection(Branch::query()->where('is_active', true)->orderBy('name')->get()),
            'services' => ServiceResource::collection(Service::query()->where('is_active', true)->orderBy('name')->get()),
            'practitioners' => User::query()->where('is_practitioner', true)->where('is_active', true)
                ->with('branches:id')->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'title' => $u->title,
                    'specialization' => $u->specialization,
                    'branch_ids' => $u->branches->pluck('id'),
                ]),
        ]]);
    }

    public function availability(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'practitioner_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $tenant = $this->tenants->require();
        $branch = Branch::query()->where('is_active', true)->findOrFail($data['branch_id']);
        $service = Service::query()->where('is_active', true)->findOrFail($data['service_id']);
        $tz = $branch->effectiveTimezone();

        return response()->json(['data' => $availability->slots(
            (int) $data['practitioner_id'], $branch->id, CarbonImmutable::parse($data['date'], $tz), $tz,
            $service->duration_minutes, (int) $tenant->setting('portal_min_lead_minutes', 120),
        )]);
    }

    public function appointments(Request $request): JsonResponse
    {
        $all = Appointment::query()
            ->where('patient_id', $this->patient()->id)
            ->with(['practitioner', 'service', 'room', 'feedback'])
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get();

        [$upcoming, $past] = $all->partition(fn (Appointment $a) => $a->ends_at->isFuture() && in_array($a->status, AppointmentStatus::blocking(), true));

        $present = fn ($rows) => $rows->map(fn (Appointment $a) => (new AppointmentResource($a))->toArray($request) + [
            'can_cancel' => $this->canCancel($a),
            'feedback_given' => $a->feedback !== null,
        ])->values();

        return response()->json(['data' => [
            'upcoming' => $present($upcoming->sortBy('starts_at')),
            'past' => $present($past),
        ]]);
    }

    /** Self-booking; only free slots within the clinic's lead-time rule are accepted. */
    public function book(Request $request, BookingService $booking, AvailabilityService $availability): JsonResponse
    {
        $tenant = $this->tenants->require();
        if (! $tenant->setting('portal_booking_enabled', true)) {
            throw new DomainRuleViolation('Online booking is not available. Please call the clinic.', 'booking_disabled');
        }

        $data = $request->validate([
            'practitioner_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'local_start' => ['required', 'date_format:Y-m-d\TH:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $patient = $this->patient();
        $upcoming = Appointment::query()->where('patient_id', $patient->id)->where('booked_via', 'portal')
            ->whereIn('status', [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed])->where('starts_at', '>', now())->count();
        if ($upcoming >= self::MAX_UPCOMING_PORTAL_BOOKINGS) {
            throw new DomainRuleViolation('You already have several upcoming bookings. Please contact the clinic to book more.', 'booking_limit');
        }

        $branch = Branch::query()->where('is_active', true)->findOrFail($data['branch_id']);
        $service = Service::query()->where('is_active', true)->findOrFail($data['service_id']);
        $tz = $branch->effectiveTimezone();
        $localStart = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['local_start'], $tz)->startOfMinute();

        $offered = collect($availability->slots((int) $data['practitioner_id'], $branch->id, $localStart->startOfDay(), $tz,
            $service->duration_minutes, (int) $tenant->setting('portal_min_lead_minutes', 120)))->pluck('local_start');

        if (! $offered->contains($localStart->format('Y-m-d\TH:i'))) {
            throw new DomainRuleViolation('That time is no longer available. Please choose another slot.', 'slot_unavailable');
        }

        try {
            $appointment = $booking->book(new BookingRequest(
                patientId: $patient->id,
                practitionerId: (int) $data['practitioner_id'],
                branchId: $branch->id,
                localStart: $localStart,
                durationMinutes: $service->duration_minutes,
                timezone: $tz,
                serviceId: $service->id,
                notes: $data['notes'] ?? null,
                bookedVia: 'portal',
            ));
        } catch (SlotConflict) {
            // Never reveal other patients' bookings to a patient.
            throw new SlotConflict([], 'That time was just taken. Please choose another slot.');
        }

        return (new AppointmentResource($appointment->load(['practitioner', 'service', 'room'])))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, Appointment $appointment, BookingService $booking): AppointmentResource
    {
        abort_unless($appointment->patient_id === $this->patient()->id, 404);

        if (! $this->canCancel($appointment)) {
            $hours = (int) $this->tenants->require()->setting('portal_cancellation_hours', 12);
            throw new DomainRuleViolation("Appointments can be cancelled online up to {$hours} hours before the start. Please call the clinic.", 'too_late_to_cancel');
        }

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? 'Cancelled by patient';

        return new AppointmentResource($booking->transition($appointment, AppointmentStatus::Cancelled, $reason)->load(['practitioner', 'service', 'room']));
    }

    /** Active plans with today's completion state for each exercise. */
    public function treatmentPlans(Request $request): JsonResponse
    {
        $patient = $this->patient();
        $today = CarbonImmutable::now($this->tenants->require()->timezone)->toDateString();
        $weekAgo = CarbonImmutable::parse($today)->subDays(6)->toDateString();

        $plans = TreatmentPlan::query()
            ->where('patient_id', $patient->id)
            ->where('status', TreatmentPlanStatus::Active)
            ->with(['practitioner:id,name', 'exercises' => fn ($q) => $q
                ->with('exercise')
                ->withExists(['logs as done_today' => fn ($l) => $l->where('performed_on', $today)])
                ->withCount(['logs' => fn ($l) => $l->where('performed_on', '>=', $weekAgo)]),
            ])
            ->latest('start_date')
            ->get();

        return response()->json(['data' => TreatmentPlanResource::collection($plans)->toArray($request)]);
    }

    public function logExercise(Request $request): JsonResponse
    {
        $patient = $this->patient();
        $data = $request->validate([
            'treatment_plan_exercise_id' => ['required', 'integer'],
            'pain_score' => ['nullable', 'integer', 'between:0,10'],
            'notes' => ['nullable', 'string', 'max:500'],
            'done' => ['sometimes', 'boolean'],
        ]);

        $row = TreatmentPlanExercise::query()->whereKey($data['treatment_plan_exercise_id'])
            ->whereHas('plan', fn ($q) => $q->where('patient_id', $patient->id)->where('status', TreatmentPlanStatus::Active))
            ->firstOrFail();

        $today = CarbonImmutable::now($this->tenants->require()->timezone)->toDateString();

        if ($request->has('done') && ! $request->boolean('done')) {
            ExerciseLog::query()->where('treatment_plan_exercise_id', $row->id)->where('performed_on', $today)->delete();

            return response()->json(['data' => ['done_today' => false]]);
        }

        ExerciseLog::query()->updateOrCreate(
            ['treatment_plan_exercise_id' => $row->id, 'performed_on' => $today],
            ['patient_id' => $patient->id, 'pain_score' => $data['pain_score'] ?? null, 'notes' => $data['notes'] ?? null],
        );

        return response()->json(['data' => ['done_today' => true]], 201);
    }

    public function progress(): JsonResponse
    {
        return response()->json(['data' => ProgressEntryResource::collection(
            ProgressEntry::query()->where('patient_id', $this->patient()->id)->whereNotNull('pain_score')->orderBy('recorded_on')->get()
        )]);
    }

    /** Self-reported pain from the app also feeds the clinician's progress chart. */
    public function reportPain(Request $request): JsonResponse
    {
        $data = $request->validate(['pain_score' => ['required', 'integer', 'between:0,10'], 'notes' => ['nullable', 'string', 'max:500']]);

        $entry = ProgressEntry::query()->create([
            'patient_id' => $this->patient()->id,
            'recorded_on' => CarbonImmutable::now($this->tenants->require()->timezone)->toDateString(),
            'pain_score' => $data['pain_score'],
            'measure' => 'NPRS (self-reported)',
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => new ProgressEntryResource($entry)], 201);
    }

    public function invoices(Request $request): JsonResponse
    {
        return response()->json(['data' => InvoiceResource::collection(
            Invoice::query()->where('patient_id', $this->patient()->id)
                ->whereIn('status', [InvoiceStatus::Posted, InvoiceStatus::Credited])
                ->with('items')
                ->latest('issue_date')->latest('id')->get()
        )->toArray($request)]);
    }

    public function invoicePdf(Invoice $invoice, PdfRenderer $pdf): Response
    {
        abort_unless($invoice->patient_id === $this->patient()->id && $invoice->status !== InvoiceStatus::Draft, 404);

        return InvoiceController::renderPdf($invoice, $pdf, PdfRenderer::locale($this->patient()->preferred_language));
    }

    public function payInvoice(Invoice $invoice, RazorpayGateway $gateway): JsonResponse
    {
        abort_unless($invoice->patient_id === $this->patient()->id, 404);
        if ($invoice->status !== InvoiceStatus::Posted || $invoice->balance_minor <= 0) {
            throw new DomainRuleViolation('This invoice has nothing left to pay.', 'nothing_due');
        }

        $tenant = $this->tenants->require();
        $link = $gateway->createPaymentLink($tenant, $invoice, $invoice->balance_minor,
            rtrim(config('app.frontend_url'), '/').'/my/invoices?clinic='.$tenant->slug);

        return response()->json(['data' => ['url' => $link->short_url]], 201);
    }

    public function exerciseProgramPdf(TreatmentPlan $plan, PdfRenderer $pdf): Response
    {
        abort_unless($plan->patient_id === $this->patient()->id, 404);

        return TreatmentPlanController::renderPdf($plan, $pdf, PdfRenderer::locale($this->patient()->preferred_language));
    }

    public function consents(): JsonResponse
    {
        $patient = $this->patient();
        $signed = ConsentSignature::query()->where('patient_id', $patient->id)->latest('signed_at')->get();
        $signedVersions = $signed->map(fn ($s) => $s->consent_template_id.':'.$s->template_version)->all();

        return response()->json(['data' => [
            'pending' => ConsentTemplateResource::collection(ConsentTemplate::query()->where('is_active', true)->get()
                ->reject(fn ($t) => in_array($t->id.':'.$t->version, $signedVersions, true))->values()),
            'signed' => ConsentSignatureResource::collection($signed),
        ]]);
    }

    public function signConsent(Request $request, ConsentTemplate $template, ConsentService $consents): JsonResponse
    {
        $data = $request->validate([
            'signature' => ['required', 'string', 'max:800000'],
            'signer_name' => ['required', 'string', 'max:128'],
            'signer_relationship' => ['sometimes', 'in:self,parent,guardian,spouse,other'],
            'agree' => ['accepted'],
        ]);

        $signature = $consents->sign($this->patient(), $template, $data['signature'], $data['signer_name'],
            $data['signer_relationship'] ?? 'self', 'portal', null, $request);

        return (new ConsentSignatureResource($signature))->response()->setStatusCode(201);
    }

    public function feedback(Request $request): JsonResponse
    {
        $patient = $this->patient();
        $data = $request->validate([
            'appointment_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $appointment = Appointment::query()->where('patient_id', $patient->id)
            ->where('status', AppointmentStatus::Completed)->findOrFail($data['appointment_id']);

        if (Feedback::query()->where('appointment_id', $appointment->id)->exists()) {
            throw new DomainRuleViolation('Thanks, you have already rated this visit.', 'feedback_exists');
        }

        Feedback::query()->create([
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
            'practitioner_id' => $appointment->practitioner_id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        return response()->json(['message' => 'Thank you for your feedback.'], 201);
    }

    private function canCancel(Appointment $appointment): bool
    {
        $hours = (int) $this->tenants->require()->setting('portal_cancellation_hours', 12);

        return $appointment->status->isReschedulable() && $appointment->starts_at->greaterThan(now()->addHours($hours));
    }

    private function patient(): Patient
    {
        /** @var Patient */
        return Auth::guard('patient')->user();
    }
}
