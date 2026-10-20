<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\Gender;
use App\Support\BlindIndex;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A clinic's patient. Also the identity behind the "patient" guard (PWA).
 */
class Patient extends Authenticatable
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['status' => 'active', 'preferred_language' => 'en', 'portal_enabled' => false];

    protected $guarded = ['id', 'tenant_id', 'mrn', 'phone_index', 'email_index', 'portal_password',
        'portal_invite_token_hash', 'remember_token'];

    protected $hidden = ['portal_password', 'remember_token', 'portal_invite_token_hash', 'phone_index', 'email_index'];

    protected $auditExclude = ['portal_last_login_at'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'phone' => 'encrypted',
            'email' => 'encrypted',
            'address' => 'encrypted',
            'emergency_contact_phone' => 'encrypted',
            'medical_history' => 'encrypted',
            'allergies' => 'encrypted',
            'notes' => 'encrypted',
            'portal_enabled' => 'boolean',
            'portal_password' => 'hashed',
            'portal_invite_expires_at' => 'datetime',
            'portal_last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Patient $patient) {
            if ($patient->isDirty('phone')) {
                $patient->phone_index = BlindIndex::phone($patient->phone);
            }
            if ($patient->isDirty('email')) {
                $patient->email_index = BlindIndex::email($patient->email);
            }
        });
    }

    public function getAuthPassword(): string
    {
        return (string) $this->portal_password;
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->date_of_birth?->age);
    }

    /**
     * Free-text search: names/MRN via LIKE on plaintext columns; phone/email via blind index.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('mrn', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', COALESCE(last_name, '')) LIKE ?", [$like]);

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

        return $branchIds === null ? $query : $query->whereIn('primary_branch_id', $branchIds);
    }

    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }

    public function primaryPractitioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_practitioner_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function treatmentPlans(): HasMany
    {
        return $this->hasMany(TreatmentPlan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function insurancePolicies(): HasMany
    {
        return $this->hasMany(PatientInsurancePolicy::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(PatientPackage::class);
    }

    public function progressEntries(): HasMany
    {
        return $this->hasMany(ProgressEntry::class);
    }
}
