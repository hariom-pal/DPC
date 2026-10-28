<?php

declare(strict_types=1);

namespace App\Domain\Allotment\Services;

use App\Domain\Allotment\Exceptions\AllotmentRefused;
use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\UserManagement\Services\DepartmentAssignmentService;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Who works on a Pre-DPC or DPC proposal (business decisions A1–A5, 2026-10-26;
 * docs/plans/PROPOSAL-ALLOTMENT-PLAN.md).
 *
 * The Department Admin allots one Authorized Officer and one Administrative Head
 * to each proposal, and may reassign them with a reason. A DPC raised from a
 * Pre-DPC, and an amendment of a Pre-DPC, inherit the allotment they came from.
 *
 * **`isAllotted()` is the one question.** Policies, listings, dashboards, reports
 * and notification recipients all ask it, so they cannot disagree. A proposal with
 * no current allotment is allotted to nobody: it waits for the Department Admin.
 */
class ProposalAllotmentService
{
    public function __construct(
        private readonly ActivityTimelineService $timeline,
        private readonly DepartmentAssignmentService $assignments,
    ) {}

    public function current(PreDpcProposal|DpcProposal $proposal): ?ProposalAllotment
    {
        return ProposalAllotment::query()
            ->current()
            ->where($this->key($proposal), $proposal->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * Whether the user is the proposal's allotted officer in the given role.
     */
    public function isAllotted(?User $user, PreDpcProposal|DpcProposal $proposal, Role $role): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }

        $column = $this->column($role);

        if ($column === null) {
            return false;
        }

        // The DPC's own allotment, and nothing department-wide (2026-10-27): an
        // officer of any department may be this DPC's AO or AH. An allotment on
        // hold (its officer transferred or their charge ended) confers nothing.
        return $this->isEligible($user, (int) $proposal->department_id, $role)
            && ProposalAllotment::query()
                ->current()
                ->whereNull('held_at')
                ->where($this->key($proposal), $proposal->getKey())
                ->where($column, $user->id)
                ->exists();
    }

    /**
     * Whether the user is allotted to the proposal in any role.
     */
    public function isAllottedInAnyRole(?User $user, PreDpcProposal|DpcProposal $proposal): bool
    {
        return $this->isAllotted($user, $proposal, Role::AuthorizedOfficer)
            || $this->isAllotted($user, $proposal, Role::AdministrativeHead);
    }

    /**
     * Allot the proposal, or reassign it (ending the current allotment).
     *
     * A reassignment needs a reason (A5); a first allotment does not.
     *
     * @throws AllotmentRefused
     */
    public function allot(PreDpcProposal|DpcProposal $proposal, User $ao, User $ah, ?User $by, ?string $reason = null): ProposalAllotment
    {
        // One officer is never both the AO and the AH of a proposal: the AH
        // approves what the AO prepared (Phase 0, 2026-10-27).
        if ($ao->is($ah)) {
            throw new AllotmentRefused((string) __('allotments.errors.same_officer'));
        }

        $this->assertOfficer($ao, $proposal->department_id, Role::AuthorizedOfficer);
        $this->assertOfficer($ah, $proposal->department_id, Role::AdministrativeHead);

        return DB::transaction(function () use ($proposal, $ao, $ah, $by, $reason): ProposalAllotment {
            $current = ProposalAllotment::query()
                ->current()
                ->where($this->key($proposal), $proposal->getKey())
                ->lockForUpdate()
                ->first();

            $reason = $reason !== null ? trim($reason) : null;

            if ($current !== null) {
                // Never replace an officer whose signature stands (2026-10-27,
                // refined 2026-10-28): nothing changes once the proposal is approved
                // or cancelled, and while it is submitted the AO who signed the
                // submission stays. An officer who has not signed — an AH whose
                // eSign did not match, an AO of a draft or a returned proposal —
                // may be replaced; earlier signatures stay on record. The lock
                // orders this against a concurrent approval of the same proposal.
                $locked = $proposal->newQuery()->lockForUpdate()->findOrFail($proposal->getKey());
                $aoSigned = $locked->status->value === 'submitted' && $current->ao_user_id !== $ao->id;

                if ($locked->status->isTerminal() || $aoSigned) {
                    throw new AllotmentRefused((string) __('allotments.errors.locked_after_signature'));
                }

                if ($current->ao_user_id === $ao->id && $current->ah_user_id === $ah->id) {
                    throw new AllotmentRefused((string) __('allotments.errors.unchanged'));
                }

                if ($reason === null || $reason === '') {
                    throw new AllotmentRefused((string) __('allotments.errors.reason_required'));
                }

                $current->forceFill(['ended_at' => now(), 'ended_by' => $by?->id])->save();
            }

            $allotment = ProposalAllotment::query()->create([
                'department_id' => $proposal->department_id,
                $this->key($proposal) => $proposal->getKey(),
                'ao_user_id' => $ao->id,
                'ah_user_id' => $ah->id,
                'allotted_by' => $by?->id,
                'allotted_at' => now(),
                'reason' => $reason !== '' ? $reason : null,
            ]);

            // The AO and AH roles follow the allotments (2026-10-27): the incoming
            // officers gain them, the outgoing ones keep them only while another
            // current allotment grants them.
            foreach (array_unique(array_filter([$ao->id, $ah->id, $current?->ao_user_id, $current?->ah_user_id])) as $affected) {
                $this->assignments->projectRoles(User::query()->findOrFail($affected));
            }

            $this->timeline->record(
                $current === null ? 'proposal.allotted' : 'proposal.reallotted',
                $proposal,
                $current === null
                    ? "Allotted to {$ao->name} (AO) and {$ah->name} (AH)"
                    : "Reassigned to {$ao->name} (AO) and {$ah->name} (AH): {$reason}",
                ['ao_user_id' => $ao->id, 'ah_user_id' => $ah->id, 'previous_allotment_id' => $current?->id],
                $by,
            );

            return $allotment;
        });
    }

    /**
     * Carry the source's current allotment over to a proposal raised from it — a
     * DPC from its Pre-DPC, or an amendment from the version it amends (A2).
     * Nothing is written when the source has no allotment.
     */
    public function inherit(PreDpcProposal|DpcProposal $proposal, PreDpcProposal|DpcProposal $source, ?User $by): ?ProposalAllotment
    {
        $from = $this->current($source);

        if ($from === null || $this->current($proposal) !== null) {
            return null;
        }

        $allotment = ProposalAllotment::query()->create([
            'department_id' => $proposal->department_id,
            $this->key($proposal) => $proposal->getKey(),
            'ao_user_id' => $from->ao_user_id,
            'ah_user_id' => $from->ah_user_id,
            'allotted_by' => $by?->id,
            'allotted_at' => now(),
            'reason' => null,
        ]);

        $this->timeline->record('proposal.allotted', $proposal, 'Allotment inherited from '.class_basename($source).' '.$source->getKey(), [
            'ao_user_id' => $from->ao_user_id, 'ah_user_id' => $from->ah_user_id, 'inherited_from_allotment_id' => $from->id,
        ], $by);

        return $allotment;
    }

    /**
     * Limit a Pre-DPC or DPC query to what the user is currently allotted (A3).
     * A user who is neither an AO nor an AH sees nothing through this scope.
     *
     * @template TModel of PreDpcProposal|DpcProposal
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeTo(Builder $query, User $user): Builder
    {
        $model = $query->getModel();
        $key = $model instanceof PreDpcProposal ? 'pre_dpc_proposal_id' : 'dpc_proposal_id';
        $table = $model->getTable();

        return $query->whereExists(function ($sub) use ($user, $key, $table): void {
            $sub->selectRaw('1')
                ->from('proposal_allotments')
                ->whereColumn("proposal_allotments.{$key}", "{$table}.id")
                ->whereNull('proposal_allotments.ended_at')
                ->whereNull('proposal_allotments.held_at')
                ->where(function ($who) use ($user): void {
                    $who->where('proposal_allotments.ao_user_id', $user->id)
                        ->orWhere('proposal_allotments.ah_user_id', $user->id);
                });
        });
    }

    /**
     * The user ids allotted to the proposal in the given roles — the notification
     * recipients of a workflow step on it.
     *
     * @param  list<string>  $roles
     * @return list<int>
     */
    public function recipientIds(PreDpcProposal|DpcProposal $proposal, array $roles): array
    {
        $current = $this->current($proposal);

        if ($current === null) {
            return [];
        }

        $ids = [];

        if (in_array(Role::AuthorizedOfficer->value, $roles, true)) {
            $ids[] = $current->ao_user_id;
        }

        if (in_array(Role::AdministrativeHead->value, $roles, true)) {
            $ids[] = $current->ah_user_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Narrow a workflow notification to the allotted officers (A3, 2026-10-26).
     *
     * When the transition's subject belongs to a Pre-DPC or DPC proposal — the
     * proposal itself, a circulation raised from it, its proceeding — the AO and
     * AH roles leave the department-wide role list and the allotted officers are
     * named instead. A proposal with no allotment notifies no AO or AH (A4). Any
     * other subject, and every other role, is left as it was.
     *
     * @param  list<string>  $roles
     * @param  list<int>  $userIds
     * @return array{0: list<string>, 1: list<int>}
     */
    public function narrowRecipients(Model $subject, array $roles, array $userIds): array
    {
        $proposal = $this->governingProposal($subject);
        $officerRoles = [Role::AuthorizedOfficer->value, Role::AdministrativeHead->value];

        if ($proposal === null || array_intersect($roles, $officerRoles) === []) {
            return [$roles, $userIds];
        }

        return [
            array_values(array_diff($roles, $officerRoles)),
            array_values(array_unique([...$userIds, ...$this->recipientIds($proposal, $roles)])),
        ];
    }

    private function governingProposal(Model $subject): PreDpcProposal|DpcProposal|null
    {
        if ($subject instanceof PreDpcProposal || $subject instanceof DpcProposal) {
            return $subject;
        }

        if ($subject instanceof DpcCirculation) {
            return $subject->originProposal;
        }

        $id = $subject->getAttribute('dpc_proposal_id');

        return is_numeric($id) ? DpcProposal::query()->find((int) $id) : null;
    }

    /**
     * Who may be allotted as a DPC's AO or AH (2026-10-27): any active account of
     * any department that is not one of the Commission's. The role itself follows
     * from the allotment (DepartmentAssignmentService::projectRoles), so it is not
     * required beforehand; nor is a posting in the proposal's department.
     */
    public function isEligible(User $user, int $departmentId, Role $role): bool
    {
        // grantedRoleNames() reads the database, never the request's narrowed
        // `roles` relation.
        return $user->is_active
            && in_array($role, [Role::AuthorizedOfficer, Role::AdministrativeHead], true)
            && array_intersect($user->grantedRoleNames(), array_map(static fn (Role $r): string => $r->value, Role::commission())) === [];
    }

    private function assertOfficer(User $user, int $departmentId, Role $role): void
    {
        if (! $this->isEligible($user, $departmentId, $role)) {
            throw new AllotmentRefused((string) __(
                $role === Role::AuthorizedOfficer ? 'allotments.errors.not_an_ao' : 'allotments.errors.not_an_ah',
                ['name' => $user->name],
            ));
        }
    }

    private function key(PreDpcProposal|DpcProposal $proposal): string
    {
        return $proposal instanceof PreDpcProposal ? 'pre_dpc_proposal_id' : 'dpc_proposal_id';
    }

    private function column(Role $role): ?string
    {
        return match ($role) {
            Role::AuthorizedOfficer => 'ao_user_id',
            Role::AdministrativeHead => 'ah_user_id',
            default => null,
        };
    }
}
