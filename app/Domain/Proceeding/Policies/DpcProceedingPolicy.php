<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Policies;

use App\Domain\Authentication\Models\User;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Enums\Role;

/**
 * Who may conduct a DPC proceeding (PPT S31–S34) — M7 Phase 1.
 *
 * Like `DpcAllotmentPolicy`, this carries **no department check**: CGPSC users
 * hold no department, and a DPC reaches the Commission precisely by leaving
 * its department's control.
 *
 * **The allottee alone conducts the sitting** (architect ruling, AQ-6).
 * Holding the Member role is not the same as being *this* DPC's member, and
 * the Chairman is not exempt: a Chairman who wishes to conduct a DPC re-allots
 * it to themselves first. That keeps exactly one accountable actor on the
 * record, which is the first question asked when a promotion is contested —
 * and it costs nothing, because M6's re-allotment already exists and records
 * its own reason.
 */
class DpcProceedingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::Chairman->value, Role::Member->value]);
    }

    /**
     * The Chairman may *read* any proceeding — he remains accountable for the
     * Commission's work — but reading is not conducting.
     */
    public function view(User $user, DpcProceeding $proceeding): bool
    {
        return $user->hasRole(Role::Chairman->value)
            || $proceeding->conducted_by === $user->id;
    }

    /**
     * Opening a sitting. The allottee check itself lives in the service,
     * because at this point no proceeding exists yet to authorise against.
     */
    public function open(User $user): bool
    {
        return $user->hasAnyRole([Role::Chairman->value, Role::Member->value]) && $user->is_active;
    }

    /**
     * Acting within a sitting — correcting, calculating, approving, cancelling.
     * The service checks this again so a non-HTTP caller is bound too.
     */
    public function act(User $user, DpcProceeding $proceeding): bool
    {
        return $user->is_active
            && $proceeding->isOpen()
            && $proceeding->conducted_by === $user->id;
    }
}
