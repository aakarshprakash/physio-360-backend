<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Services\Sequences\SequenceAllocator;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['is_active' => true];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::created(fn (Branch $branch) => SequenceAllocator::ensureBranchSequences($branch));
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('tenant_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /** Branch timezone falls back to the clinic timezone. */
    public function effectiveTimezone(): string
    {
        return $this->timezone ?: $this->tenant->timezone;
    }
}
