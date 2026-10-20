<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Schema helpers that make cross-tenant references impossible at the database level.
 *
 * Every tenant-owned table gets:
 *   - tenant_id            FK -> tenants.id
 *   - UNIQUE(tenant_id,id) so children can reference (tenant_id, id)
 * and every reference to another tenant-owned row is a composite FK
 *   (tenant_id, <col>) -> <table>(tenant_id, id)
 * so a row in tenant A can never point at a row owned by tenant B.
 *
 * Note: composite FKs never use ON DELETE SET NULL (it would null tenant_id too).
 */
class BlueprintMacros
{
    public static function register(): void
    {
        Blueprint::macro('tenantOwned', function () {
            /** @var Blueprint $this */
            $this->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $this->unique(['tenant_id', 'id'], $this->getTable().'_tenant_id_id_unique');
        });

        Blueprint::macro('tenantForeign', function (string $column, string $on, bool $nullable = false, string $onDelete = 'restrict') {
            /** @var Blueprint $this */
            $definition = $this->unsignedBigInteger($column);
            if ($nullable) {
                $definition->nullable();
            }
            $this->foreign(['tenant_id', $column], $this->getTable().'_'.$column.'_tenant_fk')
                ->references(['tenant_id', 'id'])
                ->on($on)
                ->onDelete($onDelete);

            return $definition;
        });
    }
}
