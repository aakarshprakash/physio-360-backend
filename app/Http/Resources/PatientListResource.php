<?php

namespace App\Http\Resources;

use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Minimal patient row for lists and pickers.
 *
 * @mixin Patient
 */
class PatientListResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mrn' => $this->mrn,
            'full_name' => $this->full_name,
            'age' => $this->age,
            'gender' => $this->gender?->value,
            'phone' => $this->phone,
            'city' => $this->city,
            'status' => $this->status,
            'primary_branch_id' => $this->primary_branch_id,
            'primary_practitioner' => $this->whenLoaded('primaryPractitioner', fn () => $this->primaryPractitioner?->only(['id', 'name'])),
            'last_visit_at' => $this->utcString('last_visit_at'),
            'next_visit_at' => $this->utcString('next_visit_at'),
            'created_at' => $this->iso($this->created_at),
        ];
    }

    /** Sub-select columns arrive as raw UTC "Y-m-d H:i:s" strings. */
    private function utcString(string $attribute): ?string
    {
        $value = $this->resource->getAttributes()[$attribute] ?? null;

        return $value ? CarbonImmutable::parse($value, 'UTC')->toIso8601ZuluString() : null;
    }
}
