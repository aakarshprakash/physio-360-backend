<?php

namespace App\Http\Requests;

use App\Enums\AppointmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Times are wall-clock in the branch's timezone: "local_start" = "2026-10-15T09:00".
 */
class AppointmentRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';
        $tenantId = $this->user()?->tenant_id;
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenantId);

        return [
            'patient_id' => [$creating ? 'required' : 'prohibited', 'integer', $exists('patients')->whereNull('deleted_at')],
            'practitioner_id' => [$req, 'integer', $exists('users')],
            'branch_id' => [$req, 'integer', $exists('branches')],
            'room_id' => ['nullable', 'integer', $exists('rooms')],
            'service_id' => ['nullable', 'integer', $exists('services')],
            'type' => ['sometimes', Rule::enum(AppointmentType::class)],
            'local_start' => [$req, 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:10', 'max:480'],
            'travel_buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
            'home_visit_address' => ['nullable', 'required_if:type,home_visit', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allow_outside_hours' => ['sometimes', 'boolean'],

            'recurrence' => ['sometimes', 'nullable', 'array'],
            'recurrence.weekdays' => ['required_with:recurrence', 'array', 'min:1', 'max:7'],
            'recurrence.weekdays.*' => ['integer', 'between:0,6', 'distinct'],
            'recurrence.interval' => ['nullable', 'integer', 'between:1,4'],
            'recurrence.count' => ['exclude_without:recurrence', 'nullable', 'required_without:recurrence.until', 'integer', 'between:2,52'],
            'recurrence.until' => ['exclude_without:recurrence', 'nullable', 'required_without:recurrence.count', 'date_format:Y-m-d', 'after:local_start'],
            'on_conflict' => ['sometimes', 'in:fail,skip'],
        ];
    }
}
