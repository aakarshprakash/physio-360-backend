<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\MessageChannel;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Reusable message text with {{placeholders}} (see MessageComposer::PLACEHOLDERS). */
class MessageTemplate extends Model
{
    use Auditable, BelongsToTenant;

    public const PURPOSES = ['appointment_reminder', 'payment_reminder', 'follow_up', 'birthday', 'general'];

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['locale' => 'en', 'is_default' => false, 'is_active' => true];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
