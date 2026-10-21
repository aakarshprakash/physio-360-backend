<?php

namespace App\Http\Resources;

use App\Models\Branch;
use Illuminate\Http\Request;

/** @mixin Branch */
class BranchResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'geofence_radius_m' => $this->geofence_radius_m,
        ];
    }
}
