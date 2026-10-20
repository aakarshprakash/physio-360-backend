<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\EnquiryStatus;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Resources\EnquiryResource;
use App\Http\Resources\PatientListResource;
use App\Http\Resources\PatientResource;
use App\Models\Enquiry;
use App\Models\Patient;
use App\Services\EnquiryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Enquiry (lead) management for the front desk.
 *
 * @tags Staff · Enquiries
 */
class EnquiryController extends Controller
{
    private const RELATIONS = ['assignee:id,name', 'service:id,name', 'convertedPatient:id,first_name,last_name,mrn'];

    public function __construct(private readonly EnquiryService $enquiries) {}

    /** Pipeline list with filters, plus counts per status and overdue follow-ups. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('enquiries.manage');

        $request->validate([
            'status' => ['nullable', Rule::in(array_merge(array_column(EnquiryStatus::cases(), 'value'), ['open', 'due']))],
            'source' => ['nullable', Rule::in(Enquiry::SOURCES)],
            'assigned_to' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $user = $request->user();
        $base = fn () => Enquiry::query()->visibleTo($user)
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')));

        $page = $base()
            ->search($request->input('search'))
            ->when($request->input('status'), fn ($q, $status) => match ($status) {
                'open' => $q->whereIn('status', EnquiryStatus::openValues()),
                'due' => $q->whereIn('status', EnquiryStatus::openValues())->where('next_follow_up_at', '<=', now()->endOfDay()),
                default => $q->where('status', $status),
            })
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->input('source')))
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
            ->with(self::RELATIONS)
            // Open enquiries first, earliest follow-up first, newest otherwise.
            ->orderByRaw("CASE WHEN status IN ('new','contacted','follow_up') THEN 0 ELSE 1 END")
            ->orderByRaw('next_follow_up_at IS NULL, next_follow_up_at')
            ->latest('created_at')
            ->paginate($request->integer('per_page', 25));

        $counts = $base()->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');
        $due = $base()->whereIn('status', EnquiryStatus::openValues())->where('next_follow_up_at', '<=', now()->endOfDay())->count();
        $since = now()->subDays(30);
        $recent = $base()->where('created_at', '>=', $since)->count();
        $converted = $base()->where('converted_at', '>=', $since)->count();

        return EnquiryResource::collection($page)->additional(['summary' => [
            'by_status' => collect(EnquiryStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
            'open' => (int) collect(EnquiryStatus::openValues())->sum(fn ($s) => $counts[$s] ?? 0),
            'due_today' => $due,
            'received_30d' => $recent,
            'converted_30d' => $converted,
            'conversion_rate_30d' => $recent > 0 ? round($converted / $recent * 100, 1) : 0.0,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('enquiries.manage');
        $data = $this->validated($request);
        abort_unless($request->user()->canAccessBranch((int) $data['branch_id']), 403);

        $enquiry = $this->enquiries->create($data, $request->user()->id);

        return (new EnquiryResource($enquiry->load([...self::RELATIONS, 'activities.user:id,name'])))
            ->additional(['matching_patients' => PatientListResource::collection($this->enquiries->matchingPatients($enquiry))])
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Enquiry $enquiry): JsonResponse
    {
        $this->authorizeEnquiry($request, $enquiry);

        return (new EnquiryResource($enquiry->load([...self::RELATIONS, 'activities.user:id,name'])))
            ->additional(['matching_patients' => $enquiry->status->isOpen()
                ? PatientListResource::collection($this->enquiries->matchingPatients($enquiry))
                : []])
            ->response();
    }

    public function update(Request $request, Enquiry $enquiry): EnquiryResource
    {
        $this->authorizeEnquiry($request, $enquiry);
        $enquiry->update($this->validated($request, $enquiry));

        return new EnquiryResource($enquiry->load([...self::RELATIONS, 'activities.user:id,name']));
    }

    /** Log a call/WhatsApp/email/note and optionally schedule the next follow-up. */
    public function logActivity(Request $request, Enquiry $enquiry): EnquiryResource
    {
        $this->authorizeEnquiry($request, $enquiry);

        $data = $request->validate([
            'type' => ['required', Rule::in(['note', ...EnquiryService::CONTACT_TYPES])],
            'body' => ['nullable', 'required_if:type,note', 'string', 'max:2000'],
            'next_follow_up_at' => ['nullable', 'date', 'after:now'],
        ]);

        $updated = $this->enquiries->logActivity(
            $enquiry, $data['type'], $data['body'] ?? null, $request->user()->id,
            isset($data['next_follow_up_at']) ? CarbonImmutable::parse($data['next_follow_up_at'])->utc() : null,
        );

        return new EnquiryResource($updated->load([...self::RELATIONS, 'activities.user:id,name']));
    }

    public function transition(Request $request, Enquiry $enquiry): EnquiryResource
    {
        $this->authorizeEnquiry($request, $enquiry);

        $data = $request->validate([
            'status' => ['required', Rule::enum(EnquiryStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $this->enquiries->transition($enquiry, EnquiryStatus::from($data['status']), $data['reason'] ?? null, $request->user()->id);

        return new EnquiryResource($updated->load([...self::RELATIONS, 'activities.user:id,name']));
    }

    /** Register as a new patient (optionally with extra details) or link an existing patient. */
    public function convert(Request $request, Enquiry $enquiry): JsonResponse
    {
        $this->authorizeEnquiry($request, $enquiry);
        Gate::authorize('create', Patient::class);

        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'existing_patient_id' => ['nullable', 'integer', Rule::exists('patients', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'first_name' => ['nullable', 'string', 'max:96'],
            'last_name' => ['nullable', 'string', 'max:96'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'primary_practitioner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('is_practitioner', true)],
        ]);

        $patient = $this->enquiries->convert($enquiry, collect($data)->except('existing_patient_id')->all(), $data['existing_patient_id'] ?? null, $request->user()->id);

        return response()->json([
            'data' => new EnquiryResource($enquiry->refresh()->load([...self::RELATIONS, 'activities.user:id,name'])),
            'patient' => new PatientResource($patient->load('primaryPractitioner')),
        ]);
    }

    private function authorizeEnquiry(Request $request, Enquiry $enquiry): void
    {
        Gate::authorize('enquiries.manage');
        abort_unless($request->user()->canAccessBranch($enquiry->branch_id), 403);
    }

    private function validated(Request $request, ?Enquiry $enquiry = null): array
    {
        $required = $enquiry ? 'sometimes' : 'required';
        $tenantId = $request->user()?->tenant_id;

        return $request->validate([
            'branch_id' => [$required, 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'name' => [$required, 'string', 'max:128'],
            'phone' => [$required, 'string', 'max:32', 'regex:/^[+0-9 ()\-]{6,32}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'source' => [$required, Rule::in(Enquiry::SOURCES)],
            'interest' => ['nullable', 'string', 'max:255'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('tenant_id', $tenantId)],
            'message' => ['nullable', 'string', 'max:2000'],
            'preferred_contact_time' => ['nullable', 'string', 'max:64'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('is_active', true)],
            'next_follow_up_at' => ['nullable', 'date'],
        ]);
    }
}
