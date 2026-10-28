<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Policies;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\UserManagement\Services\DepartmentUserAuthority;
use App\Enums\Role;

/**
 * Who may act on a Pre-DPC proposal (PPT S7–S11).
 *
 * **TD-26, closed 2026-08-18.** M3 predates the architect ruling of 2026-08-13
 * that authorization lives in Policies from M4 onward, so its rules were spelled
 * out twice: `abort_unless(...)` in the controller and `$isAO` / `hasRole(...)`
 * in the Blade views. Both were enforced — this was never a security gap — but
 * two statements of one rule is the shape that lets a screen drift from the
 * check behind it, which is precisely the defect class the 2026-08-18 visibility
 * sweep was cleaning up. **The view now asks this class the same question the
 * controller asks it.**
 *
 * **Two independent axes, deliberately kept separate.**
 *
 * 1. **The department boundary.** Every ability below is scoped to the actor's
 *    own department. A Pre-DPC belongs to the department that filed it, and no
 *    other department may read it, let alone act on it.
 * 2. **The two-level split (S7).** *"यह प्रविष्ठी डी.पी.सी. हेतु अधिकृत अधिकारी
 *    द्वारा की जाएगी एवं प्रशासकीय प्रमुख द्वारा प्रविष्ठी का अनुमोदन किया
 *    जाएगा"* — the entry is made by the **Authorized Officer** and approved by
 *    the **Administrative Head**. An AH who authored the proposal would be
 *    approving their own work, so authoring and approving are disjoint here,
 *    not merely ordered.
 *
 * **Workflow state is checked by the workflow engine, not here** — with one
 * exception, `update`, because that is the ability the *screen* needs in order
 * to decide whether to offer an Edit button at all. Every other transition is
 * refused by `WorkflowEngine::assertAllowed()` with a message that says which
 * transition was attempted from which state, which is more use to the user than
 * a bare 403.
 *
 * 3. **The allotment (2026-10-26, docs/plans/PROPOSAL-ALLOTMENT-PLAN.md).** A
 *    Pre-DPC is the work of the one AO and one AH the Department Admin allotted
 *    to it — not of every AO and AH of the department. An officer who is not
 *    allotted does not see it at all (A3); a proposal with no allotment waits for
 *    the Department Admin (A4). The Department Admin opens a Pre-DPC (A1), and
 *    reads every one of the department's proposals, but never prepares or
 *    approves one.
 */
class PreDpcProposalPolicy
{
    public function __construct(private readonly ProposalAllotmentService $allotments) {}

    public function viewAny(User $user): bool
    {
        // The list is scoped by allotment, in any department (2026-10-27).
        return $user->hasAnyRole([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value]);
    }

    /**
     * The allotted AO or AH (A3). The Department Admin reads the department's
     * proposals on the allotment screen, which is not this ability.
     */
    public function view(User $user, PreDpcProposal $proposal): bool
    {
        // The allotted AO or AH, or the department's Department Admin (2026-10-28).
        return $this->allotments->isAllottedInAnyRole($user, $proposal) || $this->isItsDepartmentAdmin($user, $proposal);
    }

    /**
     * Opening a Pre-DPC is the Department Admin's (A1): the DPC year and the
     * promotion-to post, with the AO and AH allotted in the same step.
     */
    public function create(User $user): bool
    {
        return app(DepartmentUserAuthority::class)->isDepartmentAdmin($user);
    }

    /**
     * The derived pay scale, class and qualifying service the form fills in — asked
     * by the AO preparing a Pre-DPC.
     */
    public function derive(User $user): bool
    {
        // The endpoint answers only for departments the officer is allotted in, or
        // the Department Admin's own.
        return $user->hasRole(Role::AuthorizedOfficer->value) || app(DepartmentUserAuthority::class)->isDepartmentAdmin($user);
    }

    /**
     * Filling the Pre-DPC in (2026-10-28): the Department Admin builds the whole of
     * it; the allotted AO reads it and may change it before signing.
     */
    public function update(User $user, PreDpcProposal $proposal): bool
    {
        return $proposal->status->isEditable()
            && ($this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer) || $this->isItsDepartmentAdmin($user, $proposal));
    }

    /**
     * Only the allotted AO signs and submits; the Department Admin never does.
     */
    public function submit(User $user, PreDpcProposal $proposal): bool
    {
        return $this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer)
            && $proposal->status->isEditable();
    }

    public function cancel(User $user, PreDpcProposal $proposal): bool
    {
        return $this->submit($user, $proposal);
    }

    public function delete(User $user, PreDpcProposal $proposal): bool
    {
        return $this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer)
            && $proposal->status === PreDpcStatus::Draft;
    }

    public function amend(User $user, PreDpcProposal $proposal): bool
    {
        return $this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer)
            && $proposal->status->value === 'approved';
    }

    public function approve(User $user, PreDpcProposal $proposal): bool
    {
        return $this->allotments->isAllotted($user, $proposal, Role::AdministrativeHead)
            && $proposal->status->value === 'submitted';
    }

    public function return(User $user, PreDpcProposal $proposal): bool
    {
        return $this->approve($user, $proposal);
    }

    public function downloadSignature(User $user, PreDpcProposal $proposal): bool
    {
        return $this->view($user, $proposal);
    }

    private function isItsDepartmentAdmin(User $user, PreDpcProposal $proposal): bool
    {
        return app(DepartmentUserAuthority::class)->isDepartmentAdmin($user)
            && $user->department_id === $proposal->department_id;
    }
}
