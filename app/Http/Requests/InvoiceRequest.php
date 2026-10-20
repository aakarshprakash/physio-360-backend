<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Amounts are integer minor units of the clinic currency (e.g. paise, fils). */
class InvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $tenantId = $this->user()?->tenant_id;
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenantId);

        return [
            'patient_id' => [$creating ? 'required' : 'sometimes', 'integer', $exists('patients')],
            'branch_id' => [$creating ? 'required' : 'sometimes', 'integer', $exists('branches')],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payer_type' => ['sometimes', 'in:self,insurance'],
            'insurance_policy_id' => ['nullable', 'required_if:payer_type,insurance', 'integer', $exists('patient_insurance_policies')],
            'items' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:50'],
            'items.*.service_id' => ['nullable', 'integer', $exists('services')],
            'items.*.package_plan_id' => ['nullable', 'integer', $exists('package_plans'), 'prohibits:items.*.service_id'],
            'items.*.appointment_id' => ['nullable', 'integer', $exists('appointments')],
            'items.*.description' => ['nullable', 'required_without_all:items.*.service_id,items.*.package_plan_id', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'between:1,999'],
            'items.*.unit_price_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'items.*.discount_minor' => ['nullable', 'integer', 'min:0'],
            'items.*.tax_rate_bp' => ['nullable', 'integer', 'between:0,10000'],
        ];
    }
}
