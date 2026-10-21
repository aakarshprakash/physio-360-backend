<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Full employee record for the staff profile page. HR fields are only sent to
 * team managers and to the person themselves.
 *
 * @mixin User
 */
class StaffProfileResource extends Resource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $hr = $viewer && ($viewer->can('staff.manage') || $viewer->id === $this->id);
        $role = $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->first());

        return (new UserResource($this->resource))->toArray($request) + [
            'role_label' => $this->relationLoaded('roles') && ($r = Role::tryFrom((string) $this->roles->pluck('name')->first())) ? $r->label() : null,
            'role' => $role,
            'employee_code' => $this->employee_code,
            'employment_type' => $this->employment_type,
            'joined_on' => $this->joined_on?->toDateString(),
            'qualification' => $this->qualification,
            'registration_number' => $this->registration_number,
            'gender' => $this->gender,
            'geofence_exempt' => $this->geofence_exempt,
            'online_bookable' => $this->online_bookable,
            'date_of_birth' => $this->when($hr, fn () => $this->date_of_birth?->toDateString()),
            'address' => $this->when($hr, fn () => $this->address),
            'emergency_contact_name' => $this->when($hr, fn () => $this->emergency_contact_name),
            'emergency_contact_phone' => $this->when($hr, fn () => $this->emergency_contact_phone),
            'hr_notes' => $this->when($viewer?->can('staff.manage') ?? false, fn () => $this->hr_notes),
            'password_changed_at' => $this->when($hr, fn () => $this->iso($this->password_changed_at)),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
