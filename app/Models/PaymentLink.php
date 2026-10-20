<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLink extends Model
{
    use BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'created'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'expires_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
