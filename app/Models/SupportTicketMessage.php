<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    use AppendOnly, BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
