<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class Resource extends JsonResource
{
    /** UTC instant as ISO-8601 "Z" string; the SPA converts to clinic time centrally. */
    protected function iso(?CarbonInterface $value): ?string
    {
        return $value?->copy()->utc()->toIso8601ZuluString();
    }

    protected function date(?CarbonInterface $value): ?string
    {
        return $value?->toDateString();
    }
}
