<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatientRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenantId = $this->user()?->tenant_id;

        return [
            'first_name' => [$required, 'string', 'max:96'],
            'last_name' => ['nullable', 'string', 'max:96'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'phone' => [$required, 'string', 'max:32', 'regex:/^[+0-9 ()\-]{6,32}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:96'],
            'occupation' => ['nullable', 'string', 'max:128'],
            'referred_by' => ['nullable', 'string', 'max:128'],
            'emergency_contact_name' => ['nullable', 'string', 'max:128'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'medical_history' => ['nullable', 'string', 'max:5000'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'preferred_language' => ['nullable', 'in:en,ar,hi,ml,ta'],
            'primary_branch_id' => [$required, 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'primary_practitioner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('is_practitioner', true)],
            'status' => ['sometimes', 'in:active,inactive,discharged'],
        ];
    }
}
