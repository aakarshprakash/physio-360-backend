<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\PackageStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientPackage extends Model
{
    use Auditable, BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'active'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'status' => PackageStatus::class,
            'purchased_on' => 'date',
            'expires_on' => 'date',
            'sessions_total' => 'integer',
        ];
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(PackageLedgerEntry::class)->orderBy('id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PackagePlan::class, 'package_plan_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function balance(): int
    {
        return (int) $this->ledger()->sum('sessions');
    }
}
