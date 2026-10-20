<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;

/** @mixin Document */
class DocumentResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
