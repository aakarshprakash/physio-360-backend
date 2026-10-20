<?php

namespace App\Http\Resources;

use App\Models\Exercise;
use Illuminate\Http\Request;

/** @mixin Exercise */
class ExerciseResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'body_region' => $this->body_region,
            'category' => $this->category,
            'difficulty' => $this->difficulty,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'default_sets' => $this->default_sets,
            'default_reps' => $this->default_reps,
            'default_hold_seconds' => $this->default_hold_seconds,
            'video_url' => $this->video_url,
            'is_active' => $this->is_active,
        ];
    }
}
