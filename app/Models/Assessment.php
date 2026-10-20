<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\NoteStatus;
use App\Models\Concerns\SignedOnce;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Initial / re-assessment with structured findings. */
class Assessment extends Model
{
    use Auditable, BelongsToTenant, SignedOnce;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'draft'];

    protected $guarded = ['id', 'tenant_id', 'status', 'signed_at', 'signed_by', 'practitioner_id'];

    protected function casts(): array
    {
        return [
            'status' => NoteStatus::class,
            'assessed_at' => 'datetime',
            'history' => 'encrypted',
            'findings' => 'encrypted:array',
            'diagnosis' => 'encrypted',
            'goals' => 'encrypted',
            'pain_score' => 'integer',
            'signed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'practitioner_id');
    }
}
