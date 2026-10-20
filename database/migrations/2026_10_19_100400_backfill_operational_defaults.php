<?php

use App\Auth\RoleRegistrar;
use App\Models\Tenant;
use App\Services\TenantProvisioner;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;

/**
 * Grants the new module permissions (messages, attendance, leave, payroll)
 * to the role templates and gives existing clinics their leave types,
 * message templates and document counters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Tenant::query()->exists()) {
            return; // fresh install: ReferenceDataSeeder and provisioning cover it
        }

        app(RoleRegistrar::class)->sync();

        Tenant::query()->orderBy('id')->each(fn (Tenant $tenant) => app(TenantContext::class)->run(
            $tenant,
            fn (Tenant $t) => app(TenantProvisioner::class)->seedOperationalDefaults($t),
        ));
    }

    public function down(): void
    {
        // Data backfill only; the module tables' own migrations drop everything.
    }
};
