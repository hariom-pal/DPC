<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Policies;

use App\Domain\Authentication\Models\User;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
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
 */
class PreDpcProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->inDepartment($user)
            && $user->hasAnyRole([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value]);
    }

    public function view(User $user, PreDpcProposal $proposal): bool
    {
        return $this->sameDepartment($user, $proposal);
    }

    /**
     * Authoring — creating a draft, and editing one.
     *
     * The Authorized Officer's alone (S7). This is the ability whose absence
     * used to let an AH create a draft they could then neither edit, submit nor
     * cancel, because all three of those are the AO's: a dead-end record.
     */
    public function create(User $user): bool
    {
        return $this->inDepartment($user) && $user->hasRole(Role::AuthorizedOfficer->value);
    }

    public function update(User $user, PreDpcProposal $proposal): bool
    {
        return $this->create($user)
            && $this->sameDepartment($user, $proposal)
            && $proposal->status->isEditable();
    }

    public function submit(User $user, PreDpcProposal $proposal): bool
    {
        return $this->update($user, $proposal);
    }

    public function cancel(User $user, PreDpcProposal $proposal): bool
    {
        return $this->update($user, $proposal);
    }

    /**
     * Deleting a draft (business instruction, 2026-09-06).
     *
     * **Draft only, and strictly.** `update()` would also permit a *returned*
     * proposal, and a returned one has been seen by the Administrative Head and
     * carries their remarks — deleting it would erase a decision somebody made.
     * A draft has been seen by nobody, which is what makes it disposable.
     *
     * Everything past draft has its own ending already: {@see cancel()} for a
     * proposal the officer has abandoned, and a cancelled row stays on the record
     * with its status, because "this exercise was proposed and then dropped" is
     * itself worth being able to answer.
     *
     * The deletion is a **soft** one either way — see
     * `PreDpcProposalService::delete()` — so this is about who may put a draft out
     * of sight, not about who may destroy a record.
     */
    public function delete(User $user, PreDpcProposal $proposal): bool
    {
        return $this->create($user)
            && $this->sameDepartment($user, $proposal)
            && $proposal->status === PreDpcStatus::Draft;
    }

    /**
     * Amending an approved proposal into a fresh draft version.
     *
     * The AO's, like every other authoring action — but on an **approved**
     * proposal, which `update()` deliberately refuses, so it cannot be
     * expressed in terms of it.
     */
    public function amend(User $user, PreDpcProposal $proposal): bool
    {
        return $this->create($user)
            && $this->sameDepartment($user, $proposal)
            && $proposal->status->value === 'approved';
    }

    /**
     * Approving, and returning for correction — the Administrative Head's,
     * and only while the proposal is actually with them.
     */
    public function approve(User $user, PreDpcProposal $proposal): bool
    {
        return $user->hasRole(Role::AdministrativeHead->value)
            && $this->sameDepartment($user, $proposal)
            && $proposal->status->value === 'submitted';
    }

    public function return(User $user, PreDpcProposal $proposal): bool
    {
        return $this->approve($user, $proposal);
    }

    /**
     * The signed document behind an approval. Readable by anyone who may read
     * the proposal — it is evidence of what was approved, not a further action.
     */
    public function downloadSignature(User $user, PreDpcProposal $proposal): bool
    {
        return $this->view($user, $proposal);
    }

    private function sameDepartment(User $user, PreDpcProposal $proposal): bool
    {
        return $user->department_id !== null && $user->department_id === $proposal->department_id;
    }

    private function inDepartment(User $user): bool
    {
        return $user->department_id !== null;
    }
}
