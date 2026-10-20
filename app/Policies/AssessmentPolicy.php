<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;

class AssessmentPolicy
{
    public function view(User $user, Assessment $assessment): bool
    {
        return $user->can('clinical.view') && BranchAccess::patient($user, $assessment->patient);
    }

    public function update(User $user, Assessment $assessment): bool
    {
        return $user->can('clinical.write') && $assessment->practitioner_id === $user->id && ! $assessment->isSigned();
    }

    public function sign(User $user, Assessment $assessment): bool
    {
        return $this->update($user, $assessment);
    }
}
