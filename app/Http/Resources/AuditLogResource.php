<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/** @mixin AuditLog */
class AuditLogResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            'event' => $this->event,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
