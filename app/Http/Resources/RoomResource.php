<?php

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;

/** @mixin Room */
class RoomResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'type' => $this->type,
            'is_active' => $this->is_active,
        ];
    }
}
