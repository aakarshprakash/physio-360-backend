<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;

/** @mixin Service */
class ServiceResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'category' => $this->category,
            'duration_minutes' => $this->duration_minutes,
            'price_minor' => $this->price_minor,
            'tax_rate_bp' => $this->tax_rate_bp,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'description' => $this->description,
            'online_bookable' => $this->online_bookable,
        ];
    }
}
