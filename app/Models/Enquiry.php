<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\EnquiryStatus;
use App\Support\BlindIndex;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A prospective patient (lead) and its follow-up pipeline. */
class Enquiry extends Model
{
    use Auditable, BelongsToTenant;

    public const SOURCES = ['walk_in', 'phone', 'website', 'whatsapp', 'referral', 'social', 'other'];

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'new', 'source' => 'phone'];

    protected $guarded = ['id', 'tenant_id', 'phone_index', 'email_index', 'status', 'converted_patient_id', 'converted_at'];

    protected function casts(): array
    {
        return [
            'status' => EnquiryStatus::class,
            'phone' => 'encrypted',
            'email' => 'encrypted',
            'message' => 'encrypted',
            'next_follow_up_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Enquiry $enquiry) {
            if ($enquiry->isDirty('phone')) {
                $enquiry->phone_index = BlindIndex::phone($enquiry->phone);
            }
            if ($enquiry->isDirty('email')) {
                $enquiry->email_index = BlindIndex::email($enquiry->email);
            }
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')
                ->orWhere('interest', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%');
            if (str_contains($term, '@')) {
                $q->orWhere('email_index', BlindIndex::email($term));
            } elseif (strlen(BlindIndex::digits($term)) >= 6) {
                $q->orWhere('phone_index', BlindIndex::phone($term));
            }
        });
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $branchIds = $user->accessibleBranchIds();

        return $branchIds === null ? $query : $query->whereIn('branch_id', $branchIds);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(EnquiryActivity::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function convertedPatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'converted_patient_id')->withTrashed();
    }
}
