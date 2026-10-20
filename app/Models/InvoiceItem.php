<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['quantity' => 1, 'discount_minor' => 0, 'tax_rate_bp' => 0, 'tax_minor' => 0, 'sort_order' => 0];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function packagePlan(): BelongsTo
    {
        return $this->belongsTo(PackagePlan::class);
    }
}
