<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Enums\AllotmentStatus;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;

/**
 * The counts behind the dashboard's summary cards.
 *
 * **Read-only, and deliberately so.** Nothing here decides anything: no card
 * asserts that an action is permitted, no count is used by a workflow, and no
 * query writes. The cards say how much work is sitting in each state and link
 * to the screen that already governs it, so a card can never disagree with the
 * screen it points at — the screen remains the authority.
 *
 * Every count is scoped the way the corresponding screen is scoped: a
 * department officer sees only their own department, and a Member sees only
 * DPCs allotted to them.
 */
class DashboardSummaryService
{
    /**
     * Summary card groups for this user, in the order they should be shown.
     *
     * @return list<array{title:string, subtitle:string, cards:list<array{label:string, value:int, hint:string, href:string|null, tone:string}>}>
     */
    public function for(User $user): array
    {
        $groups = [];

        if ($user->hasAnyRole([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value])) {
            $groups[] = $this->departmentGroup($user);
        }

        if ($user->hasAnyRole([Role::Chairman->value, Role::Member->value])) {
            $groups[] = $this->commissionGroup($user);
        }

        if ($user->hasRole(Role::Admin->value)) {
            $groups[] = $this->administrationGroup();
        }

        return $groups;
    }

    /**
     * What the department has in flight (PPT S7–S19, S47).
     *
     * @return array{title:string, subtitle:string, cards:list<array<string, mixed>>}
     */
    private function departmentGroup(User $user): array
    {
        $departmentId = $user->department_id;
        $isHead = $user->hasRole(Role::AdministrativeHead->value);
        $departmentName = Department::query()->whereKey($departmentId)->value('name');

        $proposals = fn (): Builder => DpcProposal::query()->where('department_id', $departmentId);

        $awaitingHead = $proposals()->where('status', DpcProposalStatus::Submitted->value)->count();

        $heldOfficers = DpcProceedingHold::query()
            ->whereNull('released_at')
            ->whereIn('dpc_proceeding_id', DpcProceeding::query()
                ->whereIn('dpc_proposal_id', $proposals()->select('id'))
                ->select('id'))
            ->count();

        return [
            'title' => 'Your department',
            'subtitle' => is_string($departmentName) ? $departmentName : 'Department workload',
            'cards' => [
                [
                    'label' => 'Pre-DPC in preparation',
                    'value' => PreDpcProposal::query()
                        ->where('department_id', $departmentId)
                        ->whereIn('status', [PreDpcStatus::Draft->value, PreDpcStatus::Returned->value, PreDpcStatus::Submitted->value])
                        ->count(),
                    'hint' => 'Draft, returned or awaiting approval',
                    'href' => route('pre-dpc.index'),
                    'tone' => 'muted',
                ],
                [
                    'label' => 'DPC proposals being drafted',
                    'value' => $proposals()
                        ->whereIn('status', [DpcProposalStatus::Draft->value, DpcProposalStatus::Returned->value])
                        ->count(),
                    'hint' => 'Not yet submitted for approval',
                    'href' => route('dpc-proposals.index'),
                    'tone' => 'muted',
                ],
                [
                    'label' => $isHead ? 'Awaiting your approval' : 'With the Administrative Head',
                    'value' => $awaitingHead,
                    'hint' => $isHead ? 'Review each section, then approve once' : 'Submitted and under review',
                    'href' => route('dpc-proposals.index'),
                    'tone' => $awaitingHead > 0 ? 'action' : 'muted',
                ],
                [
                    'label' => 'Approved & forwarded to CGPSC',
                    'value' => $proposals()->where('status', DpcProposalStatus::Approved->value)->count(),
                    'hint' => 'DPC ID issued; with the Commission',
                    'href' => route('dpc-proposals.index'),
                    'tone' => 'good',
                ],
                [
                    'label' => 'Promotions held (CIR)',
                    'value' => $heldOfficers,
                    'hint' => 'Resolve through a Circulation DPC',
                    'href' => route('dpc-circulations.index'),
                    'tone' => $heldOfficers > 0 ? 'warn' : 'muted',
                ],
                [
                    'label' => 'Circulations in progress',
                    'value' => DpcCirculation::query()
                        ->where('department_id', $departmentId)
                        ->whereNotIn('status', [CirculationStatus::Signed->value, CirculationStatus::Cancelled->value])
                        ->count(),
                    'hint' => 'Raised but not yet signed by the Commission',
                    'href' => route('dpc-circulations.index'),
                    'tone' => 'muted',
                ],
            ],
        ];
    }

    /**
     * What the Commission has in front of it (PPT S26–S34, S48).
     *
     * A Chairman sees the allotment queue for the whole Commission; a Member
     * sees the DPCs that are theirs. That is the same split the screens make.
     *
     * @return array{title:string, subtitle:string, cards:list<array<string, mixed>>}
     */
    private function commissionGroup(User $user): array
    {
        $isChairman = $user->hasRole(Role::Chairman->value);

        $allottedProposalIds = DpcAllotment::query()->current()->select('dpc_proposal_id');

        $awaitingAllotment = DpcProposal::query()
            ->where('status', DpcProposalStatus::Approved->value)
            ->whereNotNull('forwarded_at')
            ->whereNotIn('id', $allottedProposalIds)
            ->count();

        $mine = fn (): Builder => DpcAllotment::query()
            ->current()
            ->where('allotted_to', $user->id);

        $openProceedings = DpcProceeding::query()
            ->where('status', DpcProceedingStatus::InProceeding->value)
            ->where('conducted_by', $user->id)
            ->count();

        // A concluded DPC keeps its allotment in the `scheduled` state — the
        // conclusion lives on the proceeding, not the allotment. Counting the
        // raw status would leave finished DPCs sitting in "awaiting the
        // sitting" forever, so the ones already sat on are excluded.
        $awaitingSitting = (clone $mine())
            ->where('status', AllotmentStatus::Scheduled->value)
            ->whereNotIn('dpc_proposal_id', DpcProceeding::query()->select('dpc_proposal_id'))
            ->count();

        $awaitingConsent = DpcCirculation::query()
            ->whereIn('status', [CirculationStatus::Approved->value, CirculationStatus::Consented->value])
            ->whereIn('origin_dpc_proceeding_id', DpcProceeding::query()->where('conducted_by', $user->id)->select('id'))
            ->count();

        /*
         * The allotment queue is the Chairman's work, and only his (PPT S28).
         * A Member was shown the same counter with the hint "With the Chairman
         * for allotment" — a number they can do nothing about, linking to a
         * screen whose one action is hidden from them. A dashboard that counts
         * other people's work teaches the reader to ignore it.
         *
         * A Member's own queue is "Allotted to you, not yet accepted" below.
         */
        $cards = [];

        if ($isChairman) {
            $cards[] = [
                'label' => 'Awaiting allotment',
                'value' => $awaitingAllotment,
                'hint' => 'Forwarded by departments; allot to yourself or a Member',
                'href' => route('cgpsc.allotments.index'),
                'tone' => $awaitingAllotment > 0 ? 'action' : 'muted',
            ];
        }

        return [
            'title' => 'Chhattisgarh Public Service Commission',
            'subtitle' => $isChairman ? 'Allotment queue and your own sittings' : 'DPCs allotted to you',
            'cards' => [
                ...$cards,
                [
                    'label' => 'Allotted to you, not yet accepted',
                    'value' => (clone $mine())->where('status', AllotmentStatus::Pending->value)->count(),
                    'hint' => 'Accept, then set the date and time',
                    'href' => route('cgpsc.schedules.index'),
                    'tone' => 'action',
                ],
                [
                    'label' => 'Accepted, awaiting a date',
                    'value' => (clone $mine())->where('status', AllotmentStatus::Accepted->value)->count(),
                    'hint' => 'Schedule the sitting',
                    'href' => route('cgpsc.schedules.index'),
                    'tone' => 'muted',
                ],
                [
                    'label' => 'Scheduled, awaiting the sitting',
                    'value' => $awaitingSitting,
                    'hint' => 'Open the sitting on the appointed day',
                    'href' => route('cgpsc.proceedings.index'),
                    'tone' => $awaitingSitting > 0 ? 'good' : 'muted',
                ],
                [
                    'label' => 'Sittings open now',
                    'value' => $openProceedings,
                    'hint' => 'Correct, calculate and approve the result',
                    'href' => route('cgpsc.proceedings.index'),
                    'tone' => $openProceedings > 0 ? 'action' : 'muted',
                ],
                [
                    'label' => 'Circulations with you',
                    'value' => $awaitingConsent,
                    'hint' => 'Decide each held officer, consent and sign',
                    'href' => route('cgpsc.circulations.index'),
                    'tone' => $awaitingConsent > 0 ? 'action' : 'muted',
                ],
                [
                    'label' => 'Sittings you have concluded',
                    'value' => DpcProceeding::query()
                        ->where('status', DpcProceedingStatus::ResultApproved->value)
                        ->where('conducted_by', $user->id)
                        ->count(),
                    'hint' => 'Result approved and documents issued',
                    'href' => route('cgpsc.proceedings.index'),
                    'tone' => 'good',
                ],
            ],
        ];
    }

    /**
     * The shape of the portal as a whole (CGPSC Admin).
     *
     * @return array{title:string, subtitle:string, cards:list<array<string, mixed>>}
     */
    private function administrationGroup(): array
    {
        return [
            'title' => 'Portal',
            'subtitle' => 'Registered departments, accounts and workload across the portal',
            'cards' => [
                [
                    'label' => 'Departments registered',
                    'value' => Department::query()->where('status', '!=', DepartmentStatus::Unregistered->value)->count(),
                    'hint' => 'Of '.Department::query()->count().' in the department master',
                    'href' => route('admin.departments.index'),
                    'tone' => 'good',
                ],
                [
                    'label' => 'Active accounts',
                    'value' => User::query()->where('is_active', true)->count(),
                    'hint' => 'Officers, Chairmen, Members and administrators',
                    'href' => route('admin.users.index'),
                    'tone' => 'muted',
                ],
                [
                    'label' => 'DPCs in the portal',
                    'value' => DpcProposal::query()->count(),
                    'hint' => 'Every proposal, at every stage',
                    'href' => null,
                    'tone' => 'muted',
                ],
                [
                    'label' => 'Sittings concluded',
                    'value' => DpcProceeding::query()->where('status', DpcProceedingStatus::ResultApproved->value)->count(),
                    'hint' => 'Results approved by the Commission',
                    'href' => null,
                    'tone' => 'good',
                ],
            ],
        ];
    }
}
