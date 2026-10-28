<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Policies;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Enums\Role;

/**
 * Who may raise, approve and read a circulation note — M9 Phase 1.
 *
 * **Department-scoped, unlike M6's and M7's policies.** A circulation begins
 * inside the department that holds the DPC, so every department-side ability
 * checks `department_id`. The Commission's abilities — consent and sign —
 * arrive with Phase 2 and will not carry a department check, for the same
 * reason M7's do not: CGPSC users hold no department.
 *
 * Every rule here is asserted **again in the service**, so a non-HTTP caller —
 * a console command, a queued job, a future API — is bound by the same rules
 * as a browser.
 */
class DpcCirculationPolicy
{
    public function __construct(private readonly ProposalAllotmentService $allotments) {}

    /**
     * A circulation is the work of the officers its originating DPC is allotted to
     * (2026-10-26, docs/plans/PROPOSAL-ALLOTMENT-PLAN.md); one without an
     * originating DPC is allotted to nobody.
     */
    private function allotted(User $user, DpcCirculation $circulation, Role $role): bool
    {
        $origin = $circulation->originProposal;

        return $origin !== null && $this->allotments->isAllotted($user, $origin, $role);
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole([
            Role::AuthorizedOfficer->value,
            Role::AdministrativeHead->value,
            Role::Chairman->value,
            Role::Member->value,
        ]);
    }

    public function view(User $user, DpcCirculation $circulation): bool
    {
        if (! $user->is_active) {
            return false;
        }

        // The Commission reads any circulation; it holds no department.
        if ($user->hasAnyRole([Role::Chairman->value, Role::Member->value])) {
            return true;
        }

        return $this->allotted($user, $circulation, Role::AuthorizedOfficer)
            || $this->allotted($user, $circulation, Role::AdministrativeHead);
    }

    /**
     * Raising one. The origin's admissibility — concluded, holds in force, not
     * superseded — is the service's job, because at this point no circulation
     * exists to authorise against.
     */
    public function create(User $user): bool
    {
        return $user->is_active
            && $user->hasRole(Role::AuthorizedOfficer->value)
            && $user->department_id !== null;
    }

    public function submit(User $user, DpcCirculation $circulation): bool
    {
        return $user->is_active
            && $this->allotted($user, $circulation, Role::AuthorizedOfficer)
            && $circulation->isEditable();
    }

    public function cancel(User $user, DpcCirculation $circulation): bool
    {
        return $this->submit($user, $circulation);
    }

    /**
     * Approving and signing (AQ-23). The Administrative Head, in the owning
     * department, on a submitted note.
     */
    public function approve(User $user, DpcCirculation $circulation): bool
    {
        return $user->is_active
            && $this->allotted($user, $circulation, Role::AdministrativeHead)
            && $circulation->status->value === 'submitted';
    }

    public function return(User $user, DpcCirculation $circulation): bool
    {
        return $this->approve($user, $circulation);
    }

    // ------------------------------------------- the Commission (M9 Phase 2)

    /**
     * Deciding an officer and consenting to the note.
     *
     * **No department check** — CGPSC users hold none, and a circulation
     * reaches the Commission precisely by leaving its department's control,
     * exactly as M6's and M7's policies work.
     *
     * **Being the origin DPC's allottee is not expressible as a role**, so it
     * is asserted in the service (AQ-20). This gate is the coarse one: an
     * active Member or Chairman, on a note the Commission is actually holding.
     */
    public function consent(User $user, DpcCirculation $circulation): bool
    {
        return $user->is_active
            && $user->hasAnyRole([Role::Chairman->value, Role::Member->value])
            && $circulation->status->isWithCommission();
    }

    /**
     * Signing — the operative act. Only from `consented`, because a note is
     * signed after its officers are decided, never before.
     */
    public function sign(User $user, DpcCirculation $circulation): bool
    {
        return $user->is_active
            && $user->hasAnyRole([Role::Chairman->value, Role::Member->value])
            && $circulation->status === CirculationStatus::Consented;
    }

    public function returnToDepartment(User $user, DpcCirculation $circulation): bool
    {
        return $this->consent($user, $circulation);
    }

    /**
     * Generating, publishing and managing the circulation's documents —
     * M9 Phase 3.
     *
     * **A separate ability from `sign`, and it has to be.** `sign` is only
     * true while the note is `consented`; documents are produced *after* it is
     * signed. Reusing `sign` would have made every document action
     * unreachable the moment the note was signed — which is exactly when they
     * become possible.
     */
    public function manageDocuments(User $user, DpcCirculation $circulation): bool
    {
        return $user->is_active
            && $user->hasAnyRole([Role::Chairman->value, Role::Member->value])
            && $circulation->status === CirculationStatus::Signed;
    }
}
