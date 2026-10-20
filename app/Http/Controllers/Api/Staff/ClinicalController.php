<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\NoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentResource;
use App\Http\Resources\ClinicalNoteResource;
use App\Http\Resources\ProgressEntryResource;
use App\Models\Assessment;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\ProgressEntry;
use App\Services\Clinical\ClinicalRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * SOAP notes, assessments and outcome measures.
 *
 * @tags Staff · Clinical records
 */
class ClinicalController extends Controller
{
    public function __construct(private readonly ClinicalRecordService $records) {}

    // ---- SOAP notes -------------------------------------------------------

    public function notes(Patient $patient): AnonymousResourceCollection
    {
        Gate::authorize('viewClinical', $patient);

        return ClinicalNoteResource::collection(
            ClinicalNote::query()->where('patient_id', $patient->id)
                ->with(['author:id,name', 'addenda.author:id,name'])
                ->latest('created_at')->latest('id')
                ->paginate(20)
        );
    }

    public function storeNote(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('writeClinical', $patient);
        $data = $this->validateNote($request, $patient);

        $note = new ClinicalNote($data);
        $note->patient_id = $patient->id;
        $note->author_id = $request->user()->id;
        $note->status = NoteStatus::Draft;
        $note->save();

        if ($request->boolean('sign')) {
            $note = $this->records->signNote($note, $request->user());
        }

        $this->recordPain($note->pain_score, $patient, $request);

        return (new ClinicalNoteResource($note->load(['author:id,name', 'addenda'])))->response()->setStatusCode(201);
    }

    public function updateNote(Request $request, ClinicalNote $note): ClinicalNoteResource
    {
        Gate::authorize('update', $note);
        $note->update($this->validateNote($request, $note->patient));

        return new ClinicalNoteResource($note->load(['author:id,name', 'addenda']));
    }

    public function destroyNote(ClinicalNote $note): JsonResponse
    {
        Gate::authorize('delete', $note);
        $note->delete();

        return response()->json(null, 204);
    }

    /** Sign a draft note. Signed notes are permanent; use addenda for corrections. */
    public function signNote(Request $request, ClinicalNote $note): ClinicalNoteResource
    {
        Gate::authorize('sign', $note);

        return new ClinicalNoteResource($this->records->signNote($note, $request->user())->load(['author:id,name', 'addenda']));
    }

    public function addAddendum(Request $request, ClinicalNote $note): ClinicalNoteResource
    {
        Gate::authorize('addendum', $note);
        $body = $request->validate(['body' => ['required', 'string', 'max:5000']])['body'];

        $this->records->addAddendum($note, $request->user(), $body);

        return new ClinicalNoteResource($note->load(['author:id,name', 'addenda.author:id,name']));
    }

    // ---- Assessments ------------------------------------------------------

    public function assessments(Patient $patient): AnonymousResourceCollection
    {
        Gate::authorize('viewClinical', $patient);

        return AssessmentResource::collection(
            Assessment::query()->where('patient_id', $patient->id)->with('practitioner:id,name')->latest('assessed_at')->get()
        );
    }

    public function storeAssessment(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('writeClinical', $patient);
        $data = $this->validateAssessment($request, $patient);

        $assessment = new Assessment($data);
        $assessment->patient_id = $patient->id;
        $assessment->practitioner_id = $request->user()->id;
        $assessment->assessed_at ??= now();
        $assessment->status = NoteStatus::Draft;
        $assessment->save();

        if ($request->boolean('sign')) {
            $assessment = $this->records->signAssessment($assessment, $request->user());
        }

        $this->recordPain($assessment->pain_score, $patient, $request);

        return (new AssessmentResource($assessment->load('practitioner:id,name')))->response()->setStatusCode(201);
    }

    public function updateAssessment(Request $request, Assessment $assessment): AssessmentResource
    {
        Gate::authorize('update', $assessment);
        $assessment->update($this->validateAssessment($request, $assessment->patient));

        return new AssessmentResource($assessment->load('practitioner:id,name'));
    }

    public function signAssessment(Request $request, Assessment $assessment): AssessmentResource
    {
        Gate::authorize('sign', $assessment);

        return new AssessmentResource($this->records->signAssessment($assessment, $request->user())->load('practitioner:id,name'));
    }

    // ---- Progress / outcome measures --------------------------------------

    public function progress(Patient $patient): AnonymousResourceCollection
    {
        Gate::authorize('viewClinical', $patient);

        return ProgressEntryResource::collection(
            ProgressEntry::query()->where('patient_id', $patient->id)->with('recorder:id,name')->orderBy('recorded_on')->orderBy('id')->get()
        );
    }

    public function storeProgress(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('writeClinical', $patient);

        $data = $request->validate([
            'recorded_on' => ['required', 'date', 'before_or_equal:today'],
            'treatment_plan_id' => ['nullable', 'integer', Rule::exists('treatment_plans', 'id')->where('patient_id', $patient->id)],
            'pain_score' => ['nullable', 'integer', 'between:0,10'],
            'function_score' => ['nullable', 'integer', 'between:0,100'],
            'measure' => ['nullable', 'string', 'max:96'],
            'value' => ['nullable', 'numeric', 'between:-9999,9999'],
            'unit' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = ProgressEntry::query()->create($data + ['patient_id' => $patient->id, 'recorded_by' => $request->user()->id]);

        return (new ProgressEntryResource($entry->load('recorder:id,name')))->response()->setStatusCode(201);
    }

    public function destroyProgress(ProgressEntry $entry): JsonResponse
    {
        Gate::authorize('writeClinical', Patient::query()->findOrFail($entry->patient_id));
        $entry->delete();

        return response()->json(null, 204);
    }

    private function validateNote(Request $request, Patient $patient): array
    {
        $data = $request->validate([
            'appointment_id' => ['nullable', 'integer', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
            'subjective' => ['nullable', 'string', 'max:10000'],
            'objective' => ['nullable', 'string', 'max:10000'],
            'assessment' => ['nullable', 'string', 'max:10000'],
            'plan' => ['nullable', 'string', 'max:10000'],
            'pain_score' => ['nullable', 'integer', 'between:0,10'],
            'sign' => ['sometimes', 'boolean'],
        ]);

        unset($data['sign']);

        return $data;
    }

    private function validateAssessment(Request $request, Patient $patient): array
    {
        $required = $request->isMethod('post') ? 'required' : 'sometimes';

        $data = $request->validate([
            'appointment_id' => ['nullable', 'integer', Rule::exists('appointments', 'id')->where('patient_id', $patient->id)],
            'assessed_at' => ['nullable', 'date'],
            'chief_complaint' => [$required, 'string', 'max:255'],
            'history' => ['nullable', 'string', 'max:10000'],
            'pain_score' => ['nullable', 'integer', 'between:0,10'],
            'pain_location' => ['nullable', 'string', 'max:128'],
            'findings' => ['nullable', 'array'],
            'findings.rom' => ['nullable', 'array', 'max:30'],
            'findings.rom.*.joint' => ['required', 'string', 'max:64'],
            'findings.rom.*.movement' => ['required', 'string', 'max:64'],
            'findings.rom.*.degrees' => ['nullable', 'numeric', 'between:-90,360'],
            'findings.strength' => ['nullable', 'array', 'max:30'],
            'findings.strength.*.muscle' => ['required', 'string', 'max:64'],
            'findings.strength.*.grade' => ['required', 'string', 'max:8'],
            'findings.special_tests' => ['nullable', 'array', 'max:30'],
            'findings.special_tests.*.name' => ['required', 'string', 'max:96'],
            'findings.special_tests.*.result' => ['required', 'in:positive,negative,inconclusive'],
            'findings.observations' => ['nullable', 'string', 'max:5000'],
            'diagnosis' => ['nullable', 'string', 'max:5000'],
            'goals' => ['nullable', 'string', 'max:5000'],
            'sign' => ['sometimes', 'boolean'],
        ]);

        unset($data['sign']);

        return $data;
    }

    /** Pain scores from notes/assessments also feed the progress chart. */
    private function recordPain(?int $pain, Patient $patient, Request $request): void
    {
        if ($pain === null) {
            return;
        }

        ProgressEntry::query()->create([
            'patient_id' => $patient->id,
            'recorded_on' => now($request->user()->tenant->timezone)->toDateString(),
            'pain_score' => $pain,
            'measure' => 'NPRS',
            'recorded_by' => $request->user()->id,
        ]);
    }
}
