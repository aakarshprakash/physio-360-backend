<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'scheduled', 'type' => 'clinic', 'travel_buffer_minutes' => 0, 'booked_via' => 'staff'];

    protected $guarded = ['id', 'tenant_id'];

    protected $auditExclude = ['reminder_sent_at'];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'type' => AppointmentType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'blocked_from' => 'datetime',
            'blocked_until' => 'datetime',
            'local_starts_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'home_visit_address' => 'encrypted',
            'travel_buffer_minutes' => 'integer',
        ];
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', AppointmentStatus::blockingValues());
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $branchIds = $user->accessibleBranchIds();

        return $branchIds === null ? $query : $query->whereIn('branch_id', $branchIds);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'practitioner_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(AppointmentSeries::class, 'series_id');
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(Feedback::class);
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }
}
