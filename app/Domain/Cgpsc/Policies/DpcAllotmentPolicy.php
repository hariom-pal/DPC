<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Policies;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Services\AllotmentService;
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
    /**
     * Who may open the allotment and scheduling workspace.
     *
     * **The allottee has to be here, and a role test alone left them out.** Since
     * the business correction of 2026-09-07 the qualification for being allotted a
     * DPC is *membership of that DPC's committee*, not a portal role —
     * {@see AllotmentService} says so explicitly, on
     * the grounds that *"sitting on one DPC's committee is not an office"*. So the
     * Chairman may now, entirely legitimately, allot a DPC to somebody who holds no
     * role at all.
     *
     * This method still asked for `Chairman` or `Member`, and the mismatch put the
     * DPC in a dead end: the allotment succeeded, the allottee was refused the only
     * screen that can **accept, decline, schedule or reschedule** it, and it did not
     * go back to the Chairman either, because nothing had lapsed. The DPC simply
     * stopped, and the reschedule control the allottee was supposed to have looked
     * like it had disappeared.
     *
     * **Nothing widens by admitting them.** This gate decides who may open the
     * screen; it never decides what is on it. {@see self::view()} and the
     * controller's own query both scope every row to `allotted_to`, so an allottee
     * who is not the Chairman sees their own DPCs and no others — exactly as a
     * Member does. {@see self::act()} is unchanged and already binds acceptance and
     * scheduling to the individual allottee rather than to any role.
     */
    public function viewAny(User $user): bool
    {
        if ($user->hasAnyRole([Role::Chairman->value, Role::Member->value])) {
            return true;
        }

        // Somebody holding a live allotment, whatever else they are.
        return DpcAllotment::query()
            ->current()
            ->where('allotted_to', $user->id)
            ->exists();
    }

    public function view(User $user, DpcAllotment $allotment): bool
    {
        // A Member sees the DPCs allotted to them; the Chairman sees all.
        return $user->hasRole(Role::Chairman->value)
            || $allotment->allotted_to === $user->id;
    }

    /**
     * Whether this actor may allot **anything** (change register of 2026-08-26,
     * item 9 as clarified).
     *
     * > *DPC will be allocated by the DPC Chairman, not by the CGPSC Chairman.*
     *
     * The DPC Chairman is designated per DPC and is drawn from that DPC's committee,
     * so they commonly hold `Role::Member` and nothing more. A policy insisting on
     * `Role::Chairman` would therefore refuse the very person the register puts in
     * charge — which is why this widened.
     *
     * **The two questions are deliberately split.** This one is class-level and can
     * only ask *"could this actor ever allot"*; the controller authorises before it
     * has a proposal in hand. Whether they may allot **this** DPC is
     * `AllotmentService::assertMayAllot()`, which checks the designation on that
     * proposal's committee and refuses a Member who is not its Chairman. Both run on
     * every allotment, and the service's check binds non-HTTP callers too.
     */
    public function allot(User $user): bool
    {
        return $user->is_active
            && $user->hasAnyRole([Role::Chairman->value, Role::Member->value]);
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
