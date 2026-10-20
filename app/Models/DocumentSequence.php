<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    use BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['next_number' => 1, 'padding' => 5];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['next_number' => 'integer', 'padding' => 'integer'];
    }
}
