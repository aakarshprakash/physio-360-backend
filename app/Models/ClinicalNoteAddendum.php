<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Models\Concerns\AppendOnly;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicalNoteAddendum extends Model
{
    use AppendOnly, Auditable, BelongsToTenant;

    protected $table = 'clinical_note_addenda';

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'created_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
