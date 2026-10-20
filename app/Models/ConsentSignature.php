<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Models\Concerns\AppendOnly;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentSignature extends Model
{
    use AppendOnly, Auditable, BelongsToTenant;

    public $timestamps = false;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['signer_relationship' => 'self'];

    protected $guarded = ['id', 'tenant_id'];

    protected $auditExclude = ['body_snapshot', 'signature_path', 'user_agent'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'template_version' => 'integer'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ConsentTemplate::class, 'consent_template_id');
    }

    public function witness(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witnessed_by');
    }
}
