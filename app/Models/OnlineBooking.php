<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\BlindIndex;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An appointment request from the public booking page (link, website widget,
 * WhatsApp). Pending until the front desk confirms it into a real appointment,
 * or confirmed straight away when the clinic uses instant booking.
 */
class OnlineBooking extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'booking_requests';

    public const STATUSES = ['pending', 'confirmed', 'declined', 'cancelled'];

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'pending', 'source' => 'link'];

    protected $guarded = ['id', 'tenant_id', 'phone_index'];

    protected function casts(): array
    {
        return [
            'requested_start' => 'datetime',
            'decided_at' => 'datetime',
            'duration_minutes' => 'integer',
            'age' => 'integer',
            'phone' => 'encrypted',
            'email' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (OnlineBooking $request) {
            if ($request->isDirty('phone')) {
                $request->phone_index = BlindIndex::phone($request->phone);
            }
        });
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $branchIds = $user->accessibleBranchIds();

        return $branchIds === null ? $query : $query->whereIn('branch_id', $branchIds);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'practitioner_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
