<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\NoteStatus;
use App\Models\Concerns\SignedOnce;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SOAP progress note. */
class ClinicalNote extends Model
{
    use Auditable, BelongsToTenant, SignedOnce;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'draft'];

    protected $guarded = ['id', 'tenant_id', 'status', 'signed_at', 'signed_by', 'content_hash', 'author_id'];

    protected function casts(): array
    {
        return [
            'status' => NoteStatus::class,
            'subjective' => 'encrypted',
            'objective' => 'encrypted',
            'assessment' => 'encrypted',
            'plan' => 'encrypted',
            'pain_score' => 'integer',
            'signed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(ClinicalNoteAddendum::class)->orderBy('created_at');
    }

    public function computeHash(): string
    {
        return hash('sha256', json_encode([
            $this->id, $this->patient_id, $this->author_id,
            $this->subjective, $this->objective, $this->assessment, $this->plan, $this->pain_score,
        ]));
    }
}
