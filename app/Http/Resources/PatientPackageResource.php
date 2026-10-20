<?php

namespace App\Http\Resources;

use App\Models\PatientPackage;
use Illuminate\Http\Request;

/** @mixin PatientPackage */
class PatientPackageResource extends Resource
{
    public function toArray(Request $request): array
    {
        $balance = $this->relationLoaded('ledger') ? (int) $this->ledger->sum('sessions') : $this->balance();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'service_id' => $this->service_id,
            'invoice_id' => $this->invoice_id,
            'sessions_total' => $this->sessions_total,
            'sessions_remaining' => $balance,
            'purchased_on' => $this->date($this->purchased_on),
            'expires_on' => $this->date($this->expires_on),
            'status' => $this->status->value,
            'ledger' => $this->whenLoaded('ledger', fn () => $this->ledger->map(fn ($e) => [
                'id' => $e->id,
                'entry_type' => $e->entry_type->value,
                'sessions' => $e->sessions,
                'appointment_id' => $e->appointment_id,
                'note' => $e->note,
                'created_at' => $this->iso($e->created_at),
            ])),
        ];
    }
}
