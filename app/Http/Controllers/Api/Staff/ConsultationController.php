<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Prescription;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Consultations: initial and follow-up assessments across the clinic, with
 * whether a prescription has been issued from each.
 *
 * @tags Staff · Consultations
 */
class ConsultationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('clinical.view');
        $request->validate([
            'practitioner_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['draft', 'signed'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $tz = $user->tenant->timezone;

        $page = Assessment::query()
            ->whereHas('patient', fn ($p) => $p->visibleTo($user))
            ->when($request->filled('practitioner_id'), fn ($q) => $q->where('practitioner_id', $request->integer('practitioner_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($q) => $q->where('assessed_at', '>=', CarbonImmutable::parse($request->input('from'), $tz)->utc()))
            ->when($request->filled('to'), fn ($q) => $q->where('assessed_at', '<', CarbonImmutable::parse($request->input('to'), $tz)->addDay()->utc()))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('chief_complaint', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $request->input('search')).'%')
                ->orWhereHas('patient', fn ($p) => $p->search($request->input('search')))))
            ->with(['patient:id,first_name,last_name,mrn,date_of_birth,gender', 'practitioner:id,name,color'])
            ->addSelect(['assessments.*', 'prescription_id' => Prescription::query()->select('id')
                ->whereColumn('prescriptions.assessment_id', 'assessments.id')->latest('id')->limit(1)])
            ->latest('assessed_at')
            ->paginate(25);

        $monthStart = CarbonImmutable::now($tz)->startOfMonth()->utc();
        $visible = fn () => Assessment::query()->whereHas('patient', fn ($p) => $p->visibleTo($user));

        return response()->json([
            'data' => collect($page->items())->map(fn (Assessment $a) => [
                'id' => $a->id,
                'patient' => [
                    'id' => $a->patient->id, 'full_name' => $a->patient->full_name, 'mrn' => $a->patient->mrn,
                    'age' => $a->patient->age, 'gender' => $a->patient->gender?->value,
                ],
                'practitioner' => ['id' => $a->practitioner->id, 'name' => $a->practitioner->name, 'color' => $a->practitioner->color],
                'assessed_at' => $a->assessed_at?->utc()->toIso8601ZuluString(),
                'chief_complaint' => $a->chief_complaint,
                'pain_score' => $a->pain_score,
                'diagnosis' => $a->diagnosis,
                'status' => $a->status->value,
                'prescription_id' => $a->prescription_id,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'summary' => [
                'this_month' => $visible()->where('assessed_at', '>=', $monthStart)->count(),
                'unsigned' => $visible()->where('status', 'draft')->count(),
                'without_prescription' => $visible()->where('assessed_at', '>=', $monthStart)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('prescriptions')->whereColumn('prescriptions.assessment_id', 'assessments.id'))->count(),
            ],
        ]);
    }
}
