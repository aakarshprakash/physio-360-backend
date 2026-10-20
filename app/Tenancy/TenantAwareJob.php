<?php

namespace App\Tenancy;

/**
 * Use in any queued job that touches tenant-owned data.
 * Call $this->captureTenant() in the constructor.
 */
trait TenantAwareJob
{
    public int $tenantId;

    protected function captureTenant(): void
    {
        $this->tenantId = app(TenantContext::class)->require()->id;
    }

    public function middleware(): array
    {
        return [new WithTenantContext($this->tenantId)];
    }
}
