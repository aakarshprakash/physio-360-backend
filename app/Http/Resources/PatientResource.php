<?php

namespace App\Http\Resources;

use App\Models\Patient;
use Illuminate\Http\Request;

/**
 * Full patient record. Medical fields are only included for staff with
 * clinical access (receptionists see demographics only).
 *
 * @mixin Patient
 */
class PatientResource extends Resource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $clinical = $user && method_exists($user, 'can') && $user->can('clinical.view');

        return [
            'id' => $this->id,
            'mrn' => $this->mrn,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'date_of_birth' => $this->date($this->date_of_birth),
            'age' => $this->age,
            'gender' => $this->gender?->value,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'occupation' => $this->occupation,
            'referred_by' => $this->referred_by,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'preferred_language' => $this->preferred_language,
            'medical_history' => $this->when($clinical, $this->medical_history),
            'allergies' => $this->when($clinical, $this->allergies),
            'notes' => $this->notes,
            'primary_branch_id' => $this->primary_branch_id,
            'primary_practitioner' => $this->whenLoaded('primaryPractitioner', fn () => $this->primaryPractitioner ? [
                'id' => $this->primaryPractitioner->id,
                'name' => $this->primaryPractitioner->name,
            ] : null),
            'status' => $this->status,
            'portal_enabled' => $this->portal_enabled,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
