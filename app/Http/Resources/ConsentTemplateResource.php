<?php

namespace App\Http\Resources;

use App\Models\ConsentTemplate;
use Illuminate\Http\Request;

/** @mixin ConsentTemplate */
class ConsentTemplateResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'locale' => $this->locale,
            'version' => $this->version,
            'is_required' => $this->is_required,
            'is_active' => $this->is_active,
        ];
    }
}
