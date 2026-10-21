<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/** Clinic staff member. Practitioners are users with is_practitioner = true. */
class User extends Authenticatable implements CanResetPassword
{
    use Auditable, BelongsToTenant, HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** Mirrors column defaults so fresh models are complete under strict mode. */
    protected $attributes = ['is_active' => true, 'is_practitioner' => false, 'color' => '#3E8E76', 'locale' => 'en', 'geofence_exempt' => false, 'online_bookable' => true, 'must_change_password' => false];

    protected $guarded = ['id', 'tenant_id', 'password', 'remember_token', 'must_change_password', 'password_changed_at'];

    protected $hidden = ['password', 'remember_token'];

    protected $auditExclude = ['last_login_at', 'password_changed_at'];

    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_practitioner' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'date_of_birth' => 'date',
            'joined_on' => 'date',
            'geofence_exempt' => 'boolean',
            'online_bookable' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * Sets a new password. $temporary forces a change at next sign-in
     * (used when an administrator issues the password).
     */
    public function setNewPassword(string $password, bool $temporary = false): void
    {
        $this->password = $password;
        $this->forceFill(['must_change_password' => $temporary, 'password_changed_at' => now()]);
        $this->setRememberToken(null);
        $this->save();
    }

    public function salary(): HasOne
    {
        return $this->hasOne(SalaryStructure::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot('tenant_id');
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }

    /** Branch ids this user may access; null means all branches. */
    public function accessibleBranchIds(): ?array
    {
        if ($this->can('branches.all')) {
            return null;
        }

        return $this->branches()->pluck('branches.id')->all();
    }

    public function canAccessBranch(int $branchId): bool
    {
        $ids = $this->accessibleBranchIds();

        return $ids === null || in_array($branchId, $ids, true);
    }
}
