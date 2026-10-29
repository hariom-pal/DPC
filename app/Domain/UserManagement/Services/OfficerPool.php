<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Services;

use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Department\Models\Department;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\UserManagement\Exceptions\AssignmentRefused;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The officers a department may allot as AO or AH (2026-10-29): its own — every
 * active account with a current assignment in the department, its Department
 * Admin aside — and every officer another department has **released**. Releasing
 * is the Department Admin's step when an AO or AH is transferred; the officer
 * stays released until a department picks them up, which posts them there.
 */
class OfficerPool
{
    public function __construct(
        private readonly DepartmentAssignmentService $assignments,
        private readonly ActivityTimelineService $timeline,
        private readonly SecurityLog $log,
    ) {}

    /**
     * @return Builder<User>
     */
    public function selectable(int $departmentId): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn (Builder $q) => $q->whereIn('name', $this->commissionRoles()))
            ->where(fn (Builder $q) => $q
                ->whereHas('activeAssignments', fn (Builder $a) => $a
                    ->where('department_id', $departmentId)
                    ->where('role', '!=', Role::DepartmentAdmin->value))
                ->orWhere(fn (Builder $r) => $r->whereNotNull('released_at')->whereDoesntHave('activeAssignments')));
    }

    /**
     * The officers of the department itself, for its User Management screen.
     *
     * @return Builder<User>
     */
    public function members(int $departmentId): Builder
    {
        return User::query()
            ->whereHas('activeAssignments', fn (Builder $a) => $a
                ->where('department_id', $departmentId)
                ->where('role', '!=', Role::DepartmentAdmin->value));
    }

    public function isSelectable(User $user, int $departmentId): bool
    {
        return $this->selectable($departmentId)->whereKey($user->id)->exists();
    }

    public function isReleased(User $user): bool
    {
        return $user->released_at !== null && ! $user->activeAssignments()->exists();
    }

    /**
     * Post a released officer to the department that has just chosen them.
     * Nothing happens for the department's own officers.
     */
    public function adopt(User $user, int $departmentId, Role $role): void
    {
        if (! $this->isReleased($user)) {
            return;
        }

        $this->assignments->assign($user, Department::query()->findOrFail($departmentId), $role, remarks: 'Picked up after release.');
    }

    /**
     * Release an officer from the department (a transfer). Refused while the
     * officer is the current AO or AH of one of the department's proposals still
     * in progress: the Department Admin allots another officer first, so no
     * proposal is ever left without one.
     *
     * @throws AssignmentRefused
     */
    public function release(User $officer, Department $department, User $actor, string $reason): void
    {
        DB::transaction(function () use ($officer, $department, $actor, $reason): void {
            $assignments = $officer->activeAssignments()
                ->where('department_id', $department->id)
                ->where('role', '!=', Role::DepartmentAdmin->value)
                ->lockForUpdate()
                ->get();

            if ($assignments->isEmpty()) {
                throw new AssignmentRefused((string) __('department.users.release.not_member', ['name' => $officer->name]));
            }

            if ($this->hasOpenWork($officer, $department->id)) {
                throw new AssignmentRefused((string) __('department.users.release.open_work', ['name' => $officer->name]));
            }

            foreach ($assignments as $assignment) {
                $assignment->isAdditionalCharge()
                    ? $this->assignments->end($assignment, now(), 'Released: '.$reason, $actor)
                    : $this->assignments->release($officer, remarks: 'Released: '.$reason);
            }

            // Released only when no posting is left anywhere; an officer still
            // posted elsewhere is simply that department's.
            if (! $officer->activeAssignments()->exists()) {
                $officer->forceFill([
                    'released_at' => now(),
                    'released_from_department_id' => $department->id,
                    'released_by' => $actor->id,
                ])->save();
            }

            $this->timeline->record('officer.released', $officer, sprintf('Released from %s: %s', $department->name, $reason), ['department_id' => $department->id], $actor);
            $this->log->record('officer.released', $officer, context: ['department_id' => (string) $department->id], actor: $actor);
        });
    }

    /**
     * Whether the officer is the current AO or AH of a proposal of this
     * department that is not yet approved or cancelled.
     */
    public function hasOpenWork(User $officer, int $departmentId): bool
    {
        $inProgress = fn (string $table) => fn ($q) => $q->selectRaw('1')->from($table)
            ->whereColumn("{$table}.id", 'proposal_allotments.'.($table === 'pre_dpc_proposals' ? 'pre_dpc_proposal_id' : 'dpc_proposal_id'))
            ->where("{$table}.department_id", $departmentId)
            ->whereNotIn("{$table}.status", ['approved', 'cancelled']);

        return ProposalAllotment::query()->current()
            ->where(fn ($q) => $q->where('ao_user_id', $officer->id)->orWhere('ah_user_id', $officer->id))
            ->where(fn ($q) => $q->whereExists($inProgress('pre_dpc_proposals'))->orWhereExists($inProgress('dpc_proposals')))
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function commissionRoles(): array
    {
        return array_map(static fn (Role $role): string => $role->value, Role::commission());
    }
}
