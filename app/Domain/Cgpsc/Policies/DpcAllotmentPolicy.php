<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Policies;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Enums\Role;

/**
 * Who may allot a DPC (PPT S27–S28) — M6 Phase 1.
 *
 * Allotment is the Chairman's alone. Unlike every policy before it, this one
 * carries no department check: CGPSC users hold no department, and a DPC
 * reaches CGPSC precisely by leaving its department's control. The scoping
 * that matters here is the role, and — from Phase 2 — whether a Member is
 * *this* DPC's allottee.
 */
class DpcAllotmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::Chairman->value, Role::Member->value]);
    }

    public function view(User $user, DpcAllotment $allotment): bool
    {
        // A Member sees the DPCs allotted to them; the Chairman sees all.
        return $user->hasRole(Role::Chairman->value)
            || $allotment->allotted_to === $user->id;
    }

    public function allot(User $user): bool
    {
        return $user->hasRole(Role::Chairman->value) && $user->is_active;
    }

    public function reallot(User $user): bool
    {
        return $this->allot($user);
    }

    /**
     * Acceptance and scheduling belong to the allottee alone.
     *
     * Holding the Member role is not the same as being *this* DPC's member —
     * the workflow config gates the role, this gates the individual, and the
     * service checks it again so a non-HTTP caller is bound too.
     */
    public function act(User $user, DpcAllotment $allotment): bool
    {
        return $user->is_active && $allotment->allotted_to === $user->id;
    }
}
