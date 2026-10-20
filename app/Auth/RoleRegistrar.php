<?php

namespace App\Auth;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotently syncs the global (tenant_id = null) role templates and their
 * permissions from PermissionMatrix. Role *assignments* are per tenant.
 */
class RoleRegistrar
{
    public function sync(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            foreach (array_keys(PermissionMatrix::PERMISSIONS) as $name) {
                Permission::findOrCreate($name, 'web');
            }

            foreach (PermissionMatrix::grants() as $roleName => $permissions) {
                $role = Role::query()->whereNull('tenant_id')->where('name', $roleName)->where('guard_name', 'web')->first()
                    ?? Role::query()->create(['name' => $roleName, 'guard_name' => 'web', 'tenant_id' => null]);
                $role->syncPermissions($permissions);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
