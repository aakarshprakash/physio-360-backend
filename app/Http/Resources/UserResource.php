<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/** @mixin User */
class UserResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'title' => $this->title,
            'specialization' => $this->specialization,
            'color' => $this->color,
            'is_practitioner' => $this->is_practitioner,
            'is_active' => $this->is_active,
            'locale' => $this->locale,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'branch_ids' => $this->whenLoaded('branches', fn () => $this->branches->pluck('id')->values()),
            'last_login_at' => $this->iso($this->last_login_at),
        ];
    }
}
