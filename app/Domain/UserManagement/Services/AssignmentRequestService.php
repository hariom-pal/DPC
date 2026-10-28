<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Services;

use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\AccountSessions;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Department\Models\Department;
use App\Domain\Identity\Services\SupportingDocumentStore;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\UserManagement\Exceptions\AssignmentRefused;
use App\Domain\UserManagement\Models\AssignmentRequest;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Officer transfers and additional charge (Phases 7–8, 2026-10-27).
 *
 * Both reuse the officer's one account and are two separate workflows:
 *
 * - **Transfer.** The receiving department's Department Admin asks; the CGPSC
 *   Super Admin approves; on the effective date the old posting ends and the new
 *   one begins, the officer's sessions end, and every proposal the officer held in
 *   the old department is put on hold for that department's Department Admin to
 *   reassign. Identity verification stands; past approvals are untouched.
 * - **Additional charge.** The additional department's Department Admin asks; the
 *   Super Admin approves; on the effective date the officer gains that department
 *   in the requested role, keeping the primary posting, until the end date if one
 *   was given. Ending it — on the date, or early — puts that department's work on
 *   hold the same way.
 *
 * Taking effect happens once per request, under a row lock (`processDue()`), so
 * the scheduler can run, re-run and overlap safely; a failure is recorded on the
 * request and never grants access.
 */
class AssignmentRequestService
{
    public function __construct(
        private readonly DepartmentAssignmentService $assignments,
        private readonly DepartmentUserAuthority $authority,
        private readonly SupportingDocumentStore $documents,
        private readonly ActivityTimelineService $timeline,
        private readonly AccountSessions $sessions,
        private readonly SecurityLog $log,
    ) {}

    /**
     * @param  array{type: string, role: string, order_number: string, order_date: string, effective_from: string, effective_to?: string|null, reason?: string|null}  $data
     *
     * @throws AssignmentRefused
     */
    public function request(User $departmentAdmin, User $officer, array $data, UploadedFile $order): AssignmentRequest
    {
        if (! $this->authority->isDepartmentAdmin($departmentAdmin)) {
            throw new AssignmentRefused((string) __('identity.errors.super_admin_only'));
        }

        $role = Role::from($data['role']);
        if (! in_array($role, Role::departmentManageable(), true)) {
            throw new AssignmentRefused((string) __('assignments.errors.role'));
        }

        $to = Department::query()->findOrFail($departmentAdmin->department_id);

        return DB::transaction(function () use ($departmentAdmin, $officer, $data, $order, $role, $to): AssignmentRequest {
            // One open request per officer at a time; the lock keeps two
            // departments from racing for the same officer.
            $officer = User::query()->lockForUpdate()->findOrFail($officer->id);

            if (AssignmentRequest::query()->open()->where('user_id', $officer->id)->exists()) {
                throw new AssignmentRefused((string) __('assignments.errors.pending'));
            }

            if (UserDepartmentAssignment::query()->active()->where('user_id', $officer->id)->where('department_id', $to->id)->exists()) {
                throw new AssignmentRefused((string) __($data['type'] === AssignmentRequest::TYPE_TRANSFER ? 'assignments.errors.own_department' : 'assignments.errors.already_in_department'));
            }

            // Only a serving departmental officer (an AO or AH with a current
            // primary posting) is transferred or given additional charge — never a
            // Super Admin, a Department Admin or a Promotion Candidate looked up
            // by e-mail.
            $primary = UserDepartmentAssignment::query()->current()->whereNotNull('primary_flag')->where('user_id', $officer->id)->first();
            if ($primary === null || ! in_array($primary->role, Role::departmentManageable(), true)) {
                throw new AssignmentRefused((string) __('assignments.errors.no_primary'));
            }

            $request = AssignmentRequest::query()->create([
                'type' => $data['type'],
                'user_id' => $officer->id,
                'from_department_id' => $primary->department_id,
                'to_department_id' => $to->id,
                'role' => $role->value,
                'order_number' => $data['order_number'],
                'order_date' => $data['order_date'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['type'] === AssignmentRequest::TYPE_ADDITIONAL_CHARGE ? ($data['effective_to'] ?? null) : null,
                'reason' => $data['reason'] ?? null,
                'status' => AssignmentRequest::STATUS_SUBMITTED,
                'requested_by' => $departmentAdmin->id,
            ] + $this->documents->store($order, 'assignment-orders'));

            $this->timeline->record("assignment.{$request->type}_requested", $officer, sprintf('%s to %s requested', $request->type, $to->name), ['request' => $request->uuid], $departmentAdmin);

            return $request;
        });
    }

    /**
     * The CGPSC Super Admin's decision. Approval of a request due today or earlier
     * takes effect at once.
     *
     * @throws AssignmentRefused
     */
    public function decide(AssignmentRequest $request, User $admin, bool $approve, ?string $reason): AssignmentRequest
    {
        if (! $admin->hasRole(Role::Admin->value)) {
            throw new AssignmentRefused((string) __('identity.errors.super_admin_only'));
        }

        if (! $approve && trim((string) $reason) === '') {
            throw new AssignmentRefused((string) __('assignments.errors.reason_required'));
        }

        DB::transaction(function () use ($request, $admin, $approve, $reason): void {
            $locked = AssignmentRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== AssignmentRequest::STATUS_SUBMITTED) {
                throw new AssignmentRefused((string) __('assignments.errors.not_pending'));
            }

            $locked->forceFill([
                'status' => $approve ? AssignmentRequest::STATUS_APPROVED : AssignmentRequest::STATUS_REJECTED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            $this->timeline->record('assignment.'.($approve ? 'approved' : 'rejected'), $locked->user, "{$locked->type} request ".($approve ? 'approved' : 'rejected'), ['request' => $locked->uuid], $admin);
        });

        if ($approve) {
            $this->processDue($request->id);
        }

        return $request->refresh();
    }

    /**
     * @throws AssignmentRefused
     */
    public function cancel(AssignmentRequest $request, User $departmentAdmin): void
    {
        DB::transaction(function () use ($request, $departmentAdmin): void {
            $locked = AssignmentRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== AssignmentRequest::STATUS_SUBMITTED || $locked->to_department_id !== $departmentAdmin->department_id) {
                throw new AssignmentRefused((string) __('assignments.errors.not_pending'));
            }

            $locked->forceFill(['status' => AssignmentRequest::STATUS_CANCELLED])->save();
            $this->timeline->record('assignment.cancelled', $locked->user, "{$locked->type} request cancelled", ['request' => $locked->uuid], $departmentAdmin);
        });
    }

    /**
     * Put into effect every approved request due by today (or only `$onlyId`), and
     * end every additional charge past its end date. Safe to run again at any time.
     *
     * @return array{effective: int, failed: int, expired: int}
     */
    public function processDue(?int $onlyId = null): array
    {
        $effective = $failed = 0;

        $due = AssignmentRequest::query()
            ->where('status', AssignmentRequest::STATUS_APPROVED)
            ->whereDate('effective_from', '<=', today()->toDateString())
            ->when($onlyId !== null, fn ($q) => $q->whereKey($onlyId))
            ->pluck('id');

        foreach ($due as $id) {
            try {
                DB::transaction(function () use ($id): void {
                    $request = AssignmentRequest::query()->with(['user', 'toDepartment'])->lockForUpdate()->findOrFail($id);

                    // Already taken effect (or failed) in another run: nothing to do.
                    if ($request->status !== AssignmentRequest::STATUS_APPROVED) {
                        return;
                    }

                    // An account deactivated since the approval gains nothing.
                    if (! $request->user->is_active) {
                        throw new AssignmentRefused('The officer account is inactive.');
                    }

                    $assignment = $request->type === AssignmentRequest::TYPE_TRANSFER
                        ? $this->applyTransfer($request)
                        : $this->applyAdditionalCharge($request);

                    $request->forceFill([
                        'status' => AssignmentRequest::STATUS_EFFECTIVE,
                        'processed_at' => now(),
                        'resulting_assignment_id' => $assignment->id,
                    ])->save();

                    $this->log->record('assignment.effective', $request->user, context: ['type' => $request->type, 'department_id' => $request->to_department_id, 'role' => $request->role->value], actor: $request->decidedBy);
                });
                $effective++;
            } catch (Throwable $e) {
                AssignmentRequest::query()->whereKey($id)->where('status', AssignmentRequest::STATUS_APPROVED)->update([
                    'status' => AssignmentRequest::STATUS_FAILED,
                    'failure_reason' => mb_substr($e->getMessage(), 0, 1000),
                    'processed_at' => now(),
                ]);
                Log::error('An approved assignment request could not take effect.', ['request' => $id, 'error' => $e::class]);
                $failedRequest = AssignmentRequest::query()->with('user')->find($id);
                if ($failedRequest !== null) {
                    $this->log->record('assignment.failed', $failedRequest->user, context: ['type' => $failedRequest->type, 'department_id' => $failedRequest->to_department_id]);
                }
                $failed++;
            }
        }

        return ['effective' => $effective, 'failed' => $failed, 'expired' => $onlyId === null ? $this->expireAdditionalCharges() : 0];
    }

    /**
     * End an additional charge early (its department's Department Admin, or the
     * CGPSC Super Admin), with a reason.
     *
     * @throws AssignmentRefused
     */
    public function endAdditionalCharge(UserDepartmentAssignment $assignment, User $actor, string $reason): void
    {
        $allowed = $actor->hasRole(Role::Admin->value)
            || ($this->authority->isDepartmentAdmin($actor) && $actor->department_id === $assignment->department_id);

        if (! $allowed || ! $assignment->isAdditionalCharge() || $assignment->active_flag === null) {
            throw new AssignmentRefused((string) __('assignments.errors.not_pending'));
        }

        if (trim($reason) === '') {
            throw new AssignmentRefused((string) __('assignments.errors.reason_required'));
        }

        DB::transaction(function () use ($assignment, $actor, $reason): void {
            $this->assignments->end($assignment, Carbon::today(), $reason, $actor);
            $this->hold($assignment->user()->firstOrFail(), (int) $assignment->department_id, 'Additional charge ended');
        });
    }

    private function applyTransfer(AssignmentRequest $request): UserDepartmentAssignment
    {
        $officer = $request->user;
        $from = $request->from_department_id;

        $assignment = $this->assignments->assign($officer, $request->toDepartment, $request->role, $request->effective_from, "Transfer order {$request->order_number}");
        $assignment->forceFill(['approved_by' => $request->decided_by, 'approved_at' => $request->decided_at])->save();

        if ($from !== null) {
            $this->hold($officer, $from, 'Officer transferred');
        }

        // The officer leaves the old department now, on every device.
        $this->sessions->invalidate($officer, 'transferred', $request->decidedBy);

        return $assignment;
    }

    private function applyAdditionalCharge(AssignmentRequest $request): UserDepartmentAssignment
    {
        return $this->assignments->addAdditionalCharge(
            $request->user,
            $request->toDepartment,
            $request->role,
            $request->effective_from,
            $request->effective_to,
            $request->decidedBy,
            "Additional-charge order {$request->order_number}",
        );
    }

    private function expireAdditionalCharges(): int
    {
        $expired = UserDepartmentAssignment::query()
            ->with('user')
            ->whereNotNull('active_flag')
            ->where('kind', UserDepartmentAssignment::KIND_ADDITIONAL_CHARGE)
            ->whereNotNull('effective_to')
            ->whereDate('effective_to', '<', today()->toDateString())
            ->get();

        foreach ($expired as $assignment) {
            DB::transaction(function () use ($assignment): void {
                $this->assignments->end($assignment, Carbon::parse($assignment->effective_to), 'Additional charge expired');
                $this->hold($assignment->user, (int) $assignment->department_id, 'Additional charge expired');
            });
        }

        return $expired->count();
    }

    /**
     * Put the officer's current allotments in a department on hold until its
     * Department Admin reassigns them. The officer can no longer act on them
     * already (no current assignment there); this is what lists them for
     * reassignment.
     */
    private function hold(User $officer, int $departmentId, string $reason): void
    {
        ProposalAllotment::query()
            ->current()
            ->where('department_id', $departmentId)
            ->where(fn ($q) => $q->where('ao_user_id', $officer->id)->orWhere('ah_user_id', $officer->id))
            ->whereNull('held_at')
            ->get()
            ->each(function (ProposalAllotment $allotment) use ($officer, $reason): void {
                $allotment->forceFill(['held_at' => now(), 'held_reason' => $reason])->save();

                $proposal = $allotment->preDpcProposal ?? $allotment->dpcProposal;
                if ($proposal !== null) {
                    $this->timeline->record('allotment.held', $proposal, "Allotment on hold for reassignment: {$reason} ({$officer->name})", ['allotment' => $allotment->id]);
                }
            });

        // A held allotment grants no role (2026-10-27).
        $this->assignments->projectRoles($officer);
    }
}
