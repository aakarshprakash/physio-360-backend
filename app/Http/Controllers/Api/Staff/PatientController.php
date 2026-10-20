<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\PatientRequest;
use App\Http\Resources\PatientListResource;
use App\Http\Resources\PatientResource;
use App\Models\Appointment;
use App\Models\Patient;
use App\Notifications\PortalInviteNotification;
use App\Services\PatientService;
use App\Support\BlindIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * @tags Staff · Patients
 */
class PatientController extends Controller
{
    public function __construct(private readonly PatientService $patients) {}

    /** Search and list patients visible to the current user. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Patient::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,discharged'],
            'branch_id' => ['nullable', 'integer'],
            'practitioner_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort' => ['nullable', 'in:name,-created_at,mrn'],
        ]);

        $search = $request->string('search')->toString();

        $page = Patient::query()
            ->visibleTo($request->user())
            ->search($search)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('primary_branch_id', $request->integer('branch_id')))
            ->when($request->filled('practitioner_id'), fn ($q) => $q->where('primary_practitioner_id', $request->integer('practitioner_id')))
            ->with('primaryPractitioner:id,name')
            ->addSelect([
                'last_visit_at' => Appointment::query()->selectRaw('MAX(starts_at)')
                    ->whereColumn('appointments.patient_id', 'patients.id')
                    ->where('status', 'completed'),
                'next_visit_at' => Appointment::query()->selectRaw('MIN(starts_at)')
                    ->whereColumn('appointments.patient_id', 'patients.id')
                    ->whereIn('status', ['scheduled', 'confirmed'])
                    ->where('starts_at', '>', now()),
            ])
            ->when(
                $request->input('sort', '-created_at'),
                fn ($q, $sort) => match ($sort) {
                    'name' => $q->orderBy('first_name')->orderBy('last_name'),
                    'mrn' => $q->orderBy('mrn'),
                    default => $q->orderByDesc('created_at')->orderByDesc('id'),
                }
            )
            ->paginate($request->integer('per_page', 20));

        // Phone blind-index hits are candidates (last 8 digits); confirm after decryption.
        $digits = BlindIndex::digits($search);
        if (strlen($digits) >= 6 && ! str_contains($search, '@')) {
            $page->setCollection($page->getCollection()->filter(
                fn (Patient $p) => stripos($p->full_name.' '.$p->mrn, $search) !== false || BlindIndex::phonesMatch($p->phone, $search)
            )->values());
        }

        return PatientListResource::collection($page);
    }

    public function store(PatientRequest $request): JsonResponse
    {
        Gate::authorize('create', Patient::class);
        abort_unless($request->user()->canAccessBranch($request->integer('primary_branch_id')), 403, 'You cannot register patients at this branch.');

        $patient = $this->patients->create($request->validated());

        return (new PatientResource($patient->load('primaryPractitioner')))->response()->setStatusCode(201);
    }

    public function show(Patient $patient): PatientResource
    {
        Gate::authorize('view', $patient);

        return new PatientResource($patient->load('primaryPractitioner'));
    }

    public function update(PatientRequest $request, Patient $patient): PatientResource
    {
        Gate::authorize('update', $patient);
        $patient->update($request->validated());

        return new PatientResource($patient->load('primaryPractitioner'));
    }

    /** Archive (soft delete). History, invoices and notes are retained. */
    public function destroy(Patient $patient): JsonResponse
    {
        Gate::authorize('delete', $patient);
        $patient->delete();

        return response()->json(null, 204);
    }

    /**
     * Enable the patient app and get a one-time activation link to share.
     * The link is also emailed when the patient has an email address.
     */
    public function portalInvite(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('update', $patient);

        $token = $this->patients->issuePortalInvite($patient);
        $slug = $request->user()->tenant->slug;
        $url = rtrim(config('app.frontend_url'), '/')."/my/activate?clinic={$slug}&token={$token}";

        if ($patient->email) {
            Notification::route('mail', $patient->email)
                ->notify(new PortalInviteNotification($request->user()->tenant->name, $url));
        }

        return response()->json(['data' => ['activation_url' => $url, 'expires_at' => $patient->portal_invite_expires_at->toIso8601ZuluString()]]);
    }

    public function disablePortal(Patient $patient): JsonResponse
    {
        Gate::authorize('update', $patient);
        $patient->forceFill(['portal_enabled' => false, 'portal_invite_token_hash' => null])->save();

        return response()->json(['data' => ['portal_enabled' => false]]);
    }
}
