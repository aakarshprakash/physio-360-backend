<?php

namespace App\Http\Resources;

use App\Models\PackagePlan;
use Illuminate\Http\Request;

/** @mixin PackagePlan */
class PackagePlanResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'service_id' => $this->service_id,
            'sessions_count' => $this->sessions_count,
            'price_minor' => $this->price_minor,
            'validity_days' => $this->validity_days,
            'is_active' => $this->is_active,
        ];
    }
}
