<?php

namespace App\Policies;

use App\Models\TreatmentPlan;
use App\Models\User;

class TreatmentPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('clinical.view');
    }

    public function view(User $user, TreatmentPlan $plan): bool
    {
        return $user->can('clinical.view') && BranchAccess::patient($user, $plan->patient);
    }

    public function update(User $user, TreatmentPlan $plan): bool
    {
        return $user->can('clinical.write') && BranchAccess::patient($user, $plan->patient);
    }
}
