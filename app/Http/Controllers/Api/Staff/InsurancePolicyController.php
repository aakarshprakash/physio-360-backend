<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientInsurancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Patient insurance / TPA cover used when billing to a payer.
 *
 * @tags Staff · Insurance
 */
class InsurancePolicyController extends Controller
{
    public function index(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        return response()->json(['data' => PatientInsurancePolicy::query()
            ->where('patient_id', $patient->id)->with('payer:id,name,type')->orderByDesc('is_primary')->get()
            ->map(fn ($p) => $this->present($p))]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('update', $patient);
        $policy = PatientInsurancePolicy::query()->create($this->validated($request) + ['patient_id' => $patient->id]);

        return response()->json(['data' => $this->present($policy->load('payer:id,name,type'))], 201);
    }

    public function update(Request $request, Patient $patient, PatientInsurancePolicy $policy): JsonResponse
    {
        Gate::authorize('update', $patient);
        abort_unless($policy->patient_id === $patient->id, 404);
        $policy->update($this->validated($request, true));

        return response()->json(['data' => $this->present($policy->load('payer:id,name,type'))]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'insurance_payer_id' => [$required, 'integer', Rule::exists('insurance_payers', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'policy_number' => [$required, 'string', 'max:64'],
            'member_id' => ['nullable', 'string', 'max:64'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'coverage_percent' => ['sometimes', 'integer', 'between:0,100'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
    }

    private function present(PatientInsurancePolicy $p): array
    {
        return [
            'id' => $p->id,
            'payer' => ['id' => $p->payer->id, 'name' => $p->payer->name, 'type' => $p->payer->type],
            'policy_number' => $p->policy_number,
            'member_id' => $p->member_id,
            'valid_from' => $p->valid_from?->toDateString(),
            'valid_to' => $p->valid_to?->toDateString(),
            'coverage_percent' => $p->coverage_percent,
            'is_primary' => $p->is_primary,
        ];
    }
}
