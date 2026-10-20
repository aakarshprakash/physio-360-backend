<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('billing.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.view') && $user->canAccessBranch($invoice->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->can('billing.manage');
    }

    /** Edit drafts, post, record payments, create payment links. */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.manage') && $user->canAccessBranch($invoice->branch_id);
    }

    /** Void, credit notes, refunds. */
    public function reverse(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.void') && $user->canAccessBranch($invoice->branch_id);
    }
}
