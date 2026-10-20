<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\TreatmentPlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanExerciseResource;
use App\Http\Resources\TreatmentPlanResource;
use App\Models\Branch;
use App\Models\Exercise;
use App\Models\ExerciseLog;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanExercise;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Treatment plans and their home-exercise prescriptions.
 *
 * @tags Staff · Treatment plans
 */
class TreatmentPlanController extends Controller
{
    private const RELATIONS = ['patient:id,first_name,last_name,mrn,primary_branch_id', 'practitioner:id,name', 'exercises.exercise'];

    /** All plans across patients (filterable). */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', TreatmentPlan::class);

        $request->validate([
            'status' => ['nullable', Rule::enum(TreatmentPlanStatus::class)],
            'practitioner_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $branchIds = $request->user()->accessibleBranchIds();

        return TreatmentPlanResource::collection(
            TreatmentPlan::query()
                ->when($branchIds !== null, fn ($q) => $q->whereHas('patient', fn ($p) => $p->whereIn('primary_branch_id', $branchIds)))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('practitioner_id'), fn ($q) => $q->where('practitioner_id', $request->integer('practitioner_id')))
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                    ->where('title', 'like', '%'.$request->input('search').'%')
                    ->orWhereHas('patient', fn ($p) => $p->search($request->input('search')))))
                ->with(['patient:id,first_name,last_name,mrn', 'practitioner:id,name'])
                ->withCount('exercises')
                ->latest('start_date')
                ->paginate(20)
        );
    }

    public function forPatient(Patient $patient): AnonymousResourceCollection
    {
        Gate::authorize('viewClinical', $patient);

        return TreatmentPlanResource::collection(
            TreatmentPlan::query()->where('patient_id', $patient->id)->with(self::RELATIONS)->latest('start_date')->get()
        );
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('writeClinical', $patient);

        $plan = new TreatmentPlan($this->validatePlan($request));
        $plan->patient_id = $patient->id;
        $plan->practitioner_id ??= $request->user()->id;
        $plan->save();

        foreach ($request->input('exercises', []) as $i => $row) {
            $this->createExerciseRow($plan, $row, $i);
        }

        return (new TreatmentPlanResource($plan->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(TreatmentPlan $plan): TreatmentPlanResource
    {
        Gate::authorize('view', $plan);

        return new TreatmentPlanResource($plan->load(self::RELATIONS));
    }

    public function update(Request $request, TreatmentPlan $plan): TreatmentPlanResource
    {
        Gate::authorize('update', $plan);
        $plan->update($this->validatePlan($request));

        return new TreatmentPlanResource($plan->load(self::RELATIONS));
    }

    public function addExercise(Request $request, TreatmentPlan $plan): JsonResponse
    {
        Gate::authorize('update', $plan);
        $row = $this->createExerciseRow($plan, $this->validateExerciseRow($request), (int) $plan->exercises()->max('sort_order') + 1);

        return (new PlanExerciseResource($row->load('exercise')))->response()->setStatusCode(201);
    }

    public function updateExercise(Request $request, TreatmentPlan $plan, TreatmentPlanExercise $row): PlanExerciseResource
    {
        Gate::authorize('update', $plan);
        abort_unless($row->treatment_plan_id === $plan->id, 404);

        $row->update(array_diff_key($this->validateExerciseRow($request, false), ['exercise_id' => true]));

        return new PlanExerciseResource($row->load('exercise'));
    }

    public function removeExercise(TreatmentPlan $plan, TreatmentPlanExercise $row): JsonResponse
    {
        Gate::authorize('update', $plan);
        abort_unless($row->treatment_plan_id === $plan->id, 404);
        $row->delete();

        return response()->json(null, 204);
    }

    /** Daily adherence from the patient app for the last N days. */
    public function adherence(Request $request, TreatmentPlan $plan): JsonResponse
    {
        Gate::authorize('view', $plan);
        $days = min(90, max(7, $request->integer('days', 28)));
        $since = now($plan->patient->tenant->timezone)->subDays($days - 1)->toDateString();

        $logs = ExerciseLog::query()
            ->whereIn('treatment_plan_exercise_id', $plan->exercises()->pluck('id'))
            ->where('performed_on', '>=', $since)
            ->get(['treatment_plan_exercise_id', 'performed_on', 'pain_score']);

        return response()->json(['data' => [
            'days' => $days,
            'exercise_count' => $plan->exercises()->count(),
            'logs' => $logs->map(fn ($l) => [
                'plan_exercise_id' => $l->treatment_plan_exercise_id,
                'date' => $l->performed_on->toDateString(),
                'pain_score' => $l->pain_score,
            ]),
        ]]);
    }

    /** Printable home exercise program ("exercise prescription"). */
    public function pdf(Request $request, TreatmentPlan $plan, PdfRenderer $pdf): Response
    {
        Gate::authorize('view', $plan);

        return $this->renderPdf($plan, $pdf, $request->input('lang', 'en'));
    }

    public static function renderPdf(TreatmentPlan $plan, PdfRenderer $pdf, string $locale): Response
    {
        $plan->load(['patient', 'practitioner', 'exercises.exercise', 'tenant']);
        $branch = Branch::query()->find($plan->patient->primary_branch_id);

        $content = $pdf->render('pdf.exercise-program', [
            'title' => 'Home Exercise Program - '.$plan->patient->full_name,
            'plan' => $plan,
            'tenant' => $plan->tenant,
            'branch' => $branch,
            'locale' => PdfRenderer::locale($locale),
            'generatedAt' => now($plan->tenant->timezone)->format('d M Y H:i'),
        ], $locale);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="exercise-program-'.$plan->id.'.pdf"',
        ]);
    }

    private function validatePlan(Request $request): array
    {
        $required = $request->isMethod('post') ? 'required' : 'sometimes';

        $data = $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'practitioner_id' => ['sometimes', 'integer', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)->where('is_practitioner', true)],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'goals' => ['nullable', 'string', 'max:2000'],
            'start_date' => [$required, 'date'],
            'duration_weeks' => ['sometimes', 'integer', 'between:1,52'],
            'frequency_per_week' => ['sometimes', 'integer', 'between:1,7'],
            'status' => ['sometimes', Rule::enum(TreatmentPlanStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'exercises' => ['sometimes', 'array', 'max:30'],
            'exercises.*.exercise_id' => ['required', 'integer', Rule::exists('exercises', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'exercises.*.sets' => ['nullable', 'integer', 'between:1,20'],
            'exercises.*.reps' => ['nullable', 'integer', 'between:1,200'],
            'exercises.*.hold_seconds' => ['nullable', 'integer', 'between:1,600'],
            'exercises.*.frequency' => ['nullable', 'string', 'max:64'],
            'exercises.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        unset($data['exercises']);

        return $data;
    }

    private function validateExerciseRow(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'exercise_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('exercises', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'sets' => ['nullable', 'integer', 'between:1,20'],
            'reps' => ['nullable', 'integer', 'between:1,200'],
            'hold_seconds' => ['nullable', 'integer', 'between:1,600'],
            'frequency' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function createExerciseRow(TreatmentPlan $plan, array $row, int $order): TreatmentPlanExercise
    {
        $exercise = Exercise::query()->findOrFail($row['exercise_id']);

        return $plan->exercises()->create([
            'exercise_id' => $exercise->id,
            'sets' => $row['sets'] ?? $exercise->default_sets,
            'reps' => $row['reps'] ?? $exercise->default_reps,
            'hold_seconds' => $row['hold_seconds'] ?? $exercise->default_hold_seconds,
            'frequency' => $row['frequency'] ?? 'Daily',
            'notes' => $row['notes'] ?? null,
            'sort_order' => $row['sort_order'] ?? $order,
        ]);
    }
}
