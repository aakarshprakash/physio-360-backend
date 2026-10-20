<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('patients.view');
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->can('patients.view') && BranchAccess::patient($user, $patient);
    }

    public function create(User $user): bool
    {
        return $user->can('patients.manage');
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->can('patients.manage') && BranchAccess::patient($user, $patient);
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->can('patients.delete') && BranchAccess::patient($user, $patient);
    }

    /** Clinical records of this patient (notes, assessments, plans, progress, documents). */
    public function viewClinical(User $user, Patient $patient): bool
    {
        return $user->can('clinical.view') && BranchAccess::patient($user, $patient);
    }

    public function writeClinical(User $user, Patient $patient): bool
    {
        return $user->can('clinical.write') && BranchAccess::patient($user, $patient);
    }

    public function viewBilling(User $user, Patient $patient): bool
    {
        return $user->can('billing.view') && BranchAccess::patient($user, $patient);
    }
}
