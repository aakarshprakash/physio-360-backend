<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrescriptionResource;
use App\Models\Assessment;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\TreatmentPlan;
use App\Services\Pdf\PdfRenderer;
use App\Services\Sequences\SequenceAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Printable physiotherapy prescriptions. Written by the treating
 * physiotherapist; readable by anyone with clinical access to the patient.
 *
 * @tags Staff · Prescriptions
 */
class PrescriptionController extends Controller
{
    private const RELATIONS = ['patient:id,first_name,last_name,mrn,primary_branch_id', 'practitioner:id,name'];

    /** Clinic-wide list, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('clinical.view');
        $request->validate([
            'practitioner_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $branchIds = $request->user()->accessibleBranchIds();

        return PrescriptionResource::collection(
            Prescription::query()
                ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
                ->when($request->filled('practitioner_id'), fn ($q) => $q->where('practitioner_id', $request->integer('practitioner_id')))
                ->when($request->filled('from'), fn ($q) => $q->where('issued_on', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->where('issued_on', '<=', $request->input('to')))
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                    ->where('number', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $request->input('search')).'%')
                    ->orWhereHas('patient', fn ($p) => $p->search($request->input('search')))))
                ->with(self::RELATIONS)
                ->latest('issued_on')->latest('id')
                ->paginate(25)
        );
    }

    public function forPatient(Patient $patient): AnonymousResourceCollection
    {
        Gate::authorize('viewClinical', $patient);

        return PrescriptionResource::collection(
            Prescription::query()->where('patient_id', $patient->id)->with(self::RELATIONS)->latest('issued_on')->latest('id')->get()
        );
    }

    public function store(Request $request, Patient $patient, SequenceAllocator $sequences): JsonResponse
    {
        Gate::authorize('writeClinical', $patient);
        $data = $this->validated($request, $patient);

        $prescription = DB::transaction(function () use ($data, $patient, $request, $sequences) {
            $branch = Branch::query()->findOrFail($data['branch_id'] ?? $patient->primary_branch_id);
            abort_unless($request->user()->canAccessBranch($branch->id), 403);

            $prescription = new Prescription(collect($data)->except('branch_id')->all());
            $prescription->branch_id = $branch->id;
            $prescription->patient_id = $patient->id;
            $prescription->practitioner_id = $request->user()->id;
            $prescription->issued_on ??= now($request->user()->tenant->timezone)->toDateString();
            $prescription->number = $sequences->next('prescription', 'branch:'.$branch->id, 'RX-'.$branch->code.'-', 6);
            $prescription->save();

            return $prescription;
        });

        return (new PrescriptionResource($prescription->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Prescription $prescription): PrescriptionResource
    {
        Gate::authorize('viewClinical', $prescription->patient);

        return new PrescriptionResource($prescription->load(self::RELATIONS));
    }

    /** Only the author may correct a prescription; every change is audited. */
    public function update(Request $request, Prescription $prescription): PrescriptionResource
    {
        Gate::authorize('writeClinical', $prescription->patient);
        abort_unless($prescription->practitioner_id === $request->user()->id, 403, 'Only the prescribing physiotherapist can edit this prescription.');

        $prescription->update(collect($this->validated($request, $prescription->patient, $prescription))->except('branch_id')->all());

        return new PrescriptionResource($prescription->load(self::RELATIONS));
    }

    public function pdf(Request $request, Prescription $prescription, PdfRenderer $pdf): Response
    {
        Gate::authorize('viewClinical', $prescription->patient);
        $prescription->load(['patient', 'practitioner', 'branch', 'tenant']);
        $locale = PdfRenderer::locale($request->input('lang'));

        $content = $pdf->render('pdf.prescription', [
            'title' => 'Prescription '.$prescription->number,
            'prescription' => $prescription,
            'tenant' => $prescription->tenant,
            'branch' => $prescription->branch,
            'locale' => $locale,
            'generatedAt' => now($prescription->tenant->timezone)->format('d M Y H:i'),
        ], $locale);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$prescription->number.'.pdf"',
        ]);
    }

    private function validated(Request $request, Patient $patient, ?Prescription $existing = null): array
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'assessment_id' => ['nullable', 'integer', Rule::exists('assessments', 'id')->where('tenant_id', $tenantId)->where('patient_id', $patient->id)],
            'treatment_plan_id' => ['nullable', 'integer', Rule::exists('treatment_plans', 'id')->where('tenant_id', $tenantId)->where('patient_id', $patient->id)],
            'issued_on' => ['nullable', 'date'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'modalities' => ['nullable', 'array', 'max:20'],
            'modalities.*.name' => ['required', 'string', 'max:120'],
            'modalities.*.parameters' => ['nullable', 'string', 'max:255'],
            'modalities.*.duration_minutes' => ['nullable', 'integer', 'between:1,240'],
            'modalities.*.sessions' => ['nullable', 'integer', 'between:1,100'],
            'exercises' => ['nullable', 'array', 'max:40'],
            'exercises.*.name' => ['required', 'string', 'max:160'],
            'exercises.*.dosage' => ['nullable', 'string', 'max:160'],
            'exercises.*.notes' => ['nullable', 'string', 'max:500'],
            'advice' => ['nullable', 'string', 'max:4000'],
            'precautions' => ['nullable', 'string', 'max:2000'],
            'review_on' => ['nullable', 'date'],
        ]);

        // Pre-fill from the linked assessment / plan on creation when the form left them empty.
        if (! $existing) {
            if (empty($data['diagnosis']) && ! empty($data['assessment_id'])) {
                $data['diagnosis'] = Assessment::query()->whereKey($data['assessment_id'])->first()?->diagnosis;
            }
            if (empty($data['exercises']) && ! empty($data['treatment_plan_id'])) {
                $plan = TreatmentPlan::query()->with('exercises.exercise')->find($data['treatment_plan_id']);
                $data['exercises'] = $plan?->exercises->map(fn ($row) => [
                    'name' => $row->exercise->name,
                    'dosage' => trim($row->sets.' × '.($row->reps ?? '').($row->hold_seconds ? ', hold '.$row->hold_seconds.'s' : '').' · '.$row->frequency, ' ×·'),
                    'notes' => $row->notes,
                ])->all();
            }
        }

        return $data;
    }
}
