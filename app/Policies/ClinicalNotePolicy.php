<?php

namespace App\Policies;

use App\Models\ClinicalNote;
use App\Models\User;

/** Drafts belong to their author; signed notes are read-only for everyone. */
class ClinicalNotePolicy
{
    public function view(User $user, ClinicalNote $note): bool
    {
        return $user->can('clinical.view') && BranchAccess::patient($user, $note->patient);
    }

    public function update(User $user, ClinicalNote $note): bool
    {
        return $user->can('clinical.write') && $note->author_id === $user->id && ! $note->isSigned();
    }

    public function delete(User $user, ClinicalNote $note): bool
    {
        return $this->update($user, $note);
    }

    public function sign(User $user, ClinicalNote $note): bool
    {
        return $this->update($user, $note);
    }

    public function addendum(User $user, ClinicalNote $note): bool
    {
        return $user->can('clinical.write') && $note->isSigned() && BranchAccess::patient($user, $note->patient);
    }
}
