<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Policies;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
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
    /**
     * Who may open the sitting workspace.
     *
     * **The allottee has to be here, and a role test alone left them out.** A DPC is
     * conducted by the member it was allotted to, and since 2026-09-07 the allottee
     * need hold no role: the qualification is membership of that DPC's committee,
     * which is deliberately not an office. Gated on the role, an allottee could
     * schedule the sitting and then be refused the screen that runs it.
     *
     * Nothing widens by admitting them. {@see self::view()} still requires
     * `conducted_by === $user->id` for anybody who is not the Chairman, so an
     * allottee reaches their own sitting and no other.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::Chairman->value, Role::Member->value])
            || $this->holdsAnAllotment($user);
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
        return $user->is_active
            && ($user->hasAnyRole([Role::Chairman->value, Role::Member->value])
                || $this->holdsAnAllotment($user));
    }

    /**
     * Whether a live allotment is sitting in this user's hands.
     *
     * The coarse half of the question, asked at the two moments when no proceeding
     * exists yet to authorise against. Which *particular* DPC they may open is the
     * service's business — it checks the allottee of that proposal — and `current()`
     * is the same scope the screens use, so an allotment already returned to the
     * Chairman or superseded by a re-allotment does not count.
     */
    private function holdsAnAllotment(User $user): bool
    {
        return DpcAllotment::query()
            ->current()
            ->where('allotted_to', $user->id)
            ->exists();
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
