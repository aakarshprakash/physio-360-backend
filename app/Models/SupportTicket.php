<?php

namespace App\Models;

use App\Enums\SupportTicketStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Clinic -> SYSNAC support request. The console reads these across tenants explicitly. */
class SupportTicket extends Model
{
    use BelongsToTenant;

    public const CATEGORIES = ['question', 'bug', 'billing', 'feature', 'training', 'data'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'open', 'category' => 'question', 'priority' => 'normal', 'last_reply_by' => 'clinic'];

    protected $guarded = ['id', 'tenant_id', 'number', 'status'];

    protected function casts(): array
    {
        return [
            'status' => SupportTicketStatus::class,
            'last_reply_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
