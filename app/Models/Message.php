<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Exceptions\ImmutableRecord;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound SMS / WhatsApp / email. Content is frozen once written (model
 * guard + DB trigger); only delivery state moves. Not audited row by row:
 * the table itself is the communication log.
 */
class Message extends Model
{
    use BelongsToTenant;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'queued', 'purpose' => 'general', 'attempts' => 0];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'status' => MessageStatus::class,
            'recipient' => 'encrypted',
            'body' => 'encrypted',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Message $message) {
            if ($message->isDirty(['body', 'recipient', 'channel', 'patient_id'])) {
                throw new ImmutableRecord('Message content is immutable.');
            }
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
