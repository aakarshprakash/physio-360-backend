<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use Auditable;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'active', 'plan' => 'standard', 'locale' => 'en', 'timezone' => 'Asia/Kolkata', 'country_code' => 'IN'];

    protected $guarded = ['id'];

    protected $hidden = ['gateway_credentials'];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
            'gateway_credentials' => 'encrypted:array',
            'trial_ends_at' => 'date',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
