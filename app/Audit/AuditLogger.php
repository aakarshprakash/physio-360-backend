<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function record(string $event, ?Model $subject = null, array $old = [], array $new = [], ?int $tenantId = null): AuditLog
    {
        [$actorType, $actor] = $this->actor();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::query()->create([
            'tenant_id' => $tenantId ?? $this->subjectTenantId($subject) ?? $this->tenants->id(),
            'actor_type' => $actorType,
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor instanceof Patient ? $actor->full_name : $actor?->getAttribute('name'),
            'event' => $event,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);
    }

    private function subjectTenantId(?Model $subject): ?int
    {
        if ($subject instanceof Tenant) {
            return $subject->id;
        }

        return $subject ? ($subject->getAttributes()['tenant_id'] ?? null) : null;
    }

    /** @return array{0: string, 1: Model|null} */
    private function actor(): array
    {
        foreach (['web' => 'user', 'patient' => 'patient', 'platform' => 'platform'] as $guard => $type) {
            $user = Auth::guard($guard)->user();
            if ($user instanceof User || $user instanceof Patient || $user instanceof PlatformAdmin) {
                return [$type, $user];
            }
        }

        return ['system', null];
    }
}
