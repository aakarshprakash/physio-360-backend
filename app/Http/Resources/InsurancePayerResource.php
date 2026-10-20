<?php

namespace App\Http\Resources;

use App\Models\InsurancePayer;
use Illuminate\Http\Request;

/** @mixin InsurancePayer */
class InsurancePayerResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'code' => $this->code,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'is_active' => $this->is_active,
        ];
    }
}
