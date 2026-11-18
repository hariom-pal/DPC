<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Policies;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Proceeding\Services\CalculationGate;
use App\Domain\Proceeding\Services\CommitteeSittingService;
use App\Domain\Proceeding\Services\SealedCoverService;
use App\Enums\Role;

/**
 * Ownership-based authorization for DpcProposal (architect ruling,
 * 2026-08-13: Policies from M4 onward, replacing the inline
 * `abort_unless($department_id === ...)` pattern used through M3).
 *
 * **Allotment (2026-10-26, docs/plans/PROPOSAL-ALLOTMENT-PLAN.md).** A DPC is the
 * work of the one AO and one AH it is allotted to — inherited from its Pre-DPC and
 * reassignable by the Department Admin — not of every AO and AH of the
 * department. An officer who is not allotted does not see it (A3); a proposal with
 * no allotment waits for the Department Admin (A4).
 */
class DpcProposalPolicy
{
    public function __construct(private readonly ProposalAllotmentService $allotments) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value]);
    }

    public function view(User $user, DpcProposal $proposal): bool
    {
        // The Secretary, CGPSC reads every department's DPC once it has left the
        // department's draft (2026-11-14).
        if ($this->isSecretary($user) && $proposal->status !== DpcProposalStatus::Draft) {
            return true;
        }

        return $this->allotments->isAllottedInAnyRole($user, $proposal);
    }

    /**
     * The eligibility calculation — running it and every result of it — is the DPC
     * Committee's alone (2026-11-16): a serving seat on this DPC's committee. The
     * department's officers and the Secretary do not see it.
     */
    public function viewCalculation(User $user, DpcProposal $proposal): bool
    {
        return app(CommitteeSittingService::class)->isSeatedOn($proposal, $user);
    }

    /**
     * The original Working Officers and Seniority List files and the Tabulation
     * Chart (MoM of 16 November 2026, point 9): the Administrative Head and every
     * authority after — the Secretary, the DPC Committee and the Chairman, CGPSC —
     * once the department has submitted the DPC.
     */
    public function viewRecords(User $user, DpcProposal $proposal): bool
    {
        if ($proposal->status === DpcProposalStatus::Draft) {
            return false;
        }

        return $this->isSecretary($user)
            || $user->hasRole(Role::Chairman->value)
            || $this->allotments->isAllotted($user, $proposal, Role::AdministrativeHead)
            || app(CommitteeSittingService::class)->isSeatedOn($proposal, $user);
    }

    /** The Secretary, CGPSC decides a DPC the AH approved (2026-11-14). */
    public function secretaryApprove(User $user, DpcProposal $proposal): bool
    {
        // A DPC its DPC Chairman rejected is not approved: the Secretary marks what is
        // wrong in it and sends it to the AH (2026-11-18).
        return $this->isSecretary($user) && $proposal->status === DpcProposalStatus::PendingSecretary
            && $proposal->committeeReturnStage() !== 'secretary';
    }

    /** The Secretary marks, candidate by candidate, the sections a rejected DPC is wrong in (2026-11-18). */
    public function secretaryMarkRejection(User $user, DpcProposal $proposal): bool
    {
        return $this->isSecretary($user) && $proposal->status === DpcProposalStatus::PendingSecretary
            && $proposal->committeeReturnStage() === 'secretary';
    }

    /** The AH opens the sections the Secretary marked for the AO to correct (2026-11-18). */
    public function ahOpenSections(User $user, DpcProposal $proposal): bool
    {
        return $this->allotments->isAllotted($user, $proposal, Role::AdministrativeHead)
            && $proposal->status === DpcProposalStatus::Submitted
            && $proposal->committeeReturnStage() === 'ah';
    }

    public function secretaryReturn(User $user, DpcProposal $proposal): bool
    {
        return $this->secretaryApprove($user, $proposal);
    }

    private function isSecretary(User $user): bool
    {
        return $user->hasRole(Role::Secretary->value);
    }

    /**
     * An AO may raise DPCs; *which* Pre-DPC it may raise one from is
     * {@see raiseFrom()}.
     */
    public function create(User $user): bool
    {
        // Raising is from a Pre-DPC this officer is allotted to (raiseFrom).
        return $user->hasRole(Role::AuthorizedOfficer->value);
    }

    /**
     * A DPC is raised by the AO the Pre-DPC is allotted to (A2).
     */
    public function raiseFrom(User $user, PreDpcProposal $preDpc): bool
    {
        return $this->allotments->isAllotted($user, $preDpc, Role::AuthorizedOfficer);
    }

    public function update(User $user, DpcProposal $proposal): bool
    {
        // A committee member's return locks everything but the sections it names
        // (MoM of 16 November 2026, point 3) — see updateEmployeeSection().
        return $this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer)
            && $proposal->status->isEditable()
            && $proposal->committee_returned_at === null;
    }

    /**
     * One Employee Details section (MoM of 16 November 2026, point 3): as update(),
     * and while a committee member's return is open, only the sections it names.
     */
    public function updateEmployeeSection(User $user, DpcProposal $proposal, string $section, ?DpcEvaluationRow $row = null): bool
    {
        $sections = $proposal->committeeReturnSections();

        if ($sections === null) {
            return $this->update($user, $proposal);
        }

        // Only the sections opened — for this candidate, since 2026-11-18. Without a
        // candidate in hand, any candidate's opened section (a screen's own menu).
        return $this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer)
            && $proposal->status === DpcProposalStatus::Returned
            && ($row === null ? in_array($section, $sections, true) : $proposal->committeeReturnOpens($row->id, $section));
    }

    /** The AO sends a committee return, corrected, straight back to the committee (point 3). */
    public function committeeResubmit(User $user, DpcProposal $proposal): bool
    {
        return $this->allotments->isAllotted($user, $proposal, Role::AuthorizedOfficer)
            && $proposal->status === DpcProposalStatus::Returned
            && $proposal->committee_returned_at !== null;
    }

    /**
     * A DPC Committee member records a decision on the DPC it is evaluating — agreed,
     * or returned with the sections to correct (points 2 and 3).
     */
    public function committeeDecide(User $user, DpcProposal $proposal): bool
    {
        // The DPC Chairman alone accepts or rejects the DPC, before it is held
        // (2026-11-18); once accepted, the sitting goes ahead.
        return $proposal->status === DpcProposalStatus::Approved
            && app(SealedCoverService::class)->isDpcChairman($proposal, $user)
            && ! app(CalculationGate::class)->chairmanAccepted($proposal);
    }

    /**
     * Press Calculate (2026-11-18): the DPC Chairman alone, once every member has
     * agreed, and again only after the data changed — see CalculationGate.
     */
    public function calculate(User $user, DpcProposal $proposal): bool
    {
        return app(CalculationGate::class)->mayCalculate($proposal, $user);
    }

    public function submit(User $user, DpcProposal $proposal): bool
    {
        return $this->update($user, $proposal);
    }

    public function cancel(User $user, DpcProposal $proposal): bool
    {
        return $this->update($user, $proposal);
    }

    public function approve(User $user, DpcProposal $proposal): bool
    {
        // A rejection the Secretary marked is opened for the AO, not approved (2026-11-18).
        return $this->allotments->isAllotted($user, $proposal, Role::AdministrativeHead)
            && $proposal->status === DpcProposalStatus::Submitted
            && $proposal->committeeReturnStage() !== 'ah';
    }

    public function return(User $user, DpcProposal $proposal): bool
    {
        return $this->approve($user, $proposal);
    }

    /**
     * Issuing the promotion order after the Commission's decision: the allotted AH
     * (replaces a raw department-and-role check, 2026-10-26).
     */
    public function issueOrder(User $user, DpcProposal $proposal): bool
    {
        return $this->allotments->isAllotted($user, $proposal, Role::AdministrativeHead);
    }
}
