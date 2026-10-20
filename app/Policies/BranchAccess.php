<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

/**
 * Record-level branch rule: staff see patients registered at one of their
 * branches, or who have an appointment at one. "branches.all" bypasses this.
 */
final class BranchAccess
{
    public static function patient(User $user, Patient $patient): bool
    {
        $branchIds = $user->accessibleBranchIds();

        if ($branchIds === null || in_array($patient->primary_branch_id, $branchIds, true)) {
            return true;
        }

        return $patient->appointments()->whereIn('branch_id', $branchIds)->exists();
    }
}
