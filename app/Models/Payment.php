<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\PaymentMethod;
use App\Models\Concerns\AppendOnly;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use AppendOnly, Auditable, BelongsToTenant;

    public const UPDATED_AT = null;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['type' => 'payment'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'received_at' => 'datetime',
        ];
    }

    /** Signed amount: refunds count negative. */
    public function signedAmount(): int
    {
        return $this->type === 'refund' ? -$this->amount_minor : $this->amount_minor;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
