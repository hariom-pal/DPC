<?php

declare(strict_types=1);

namespace App\Domain\Committee\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\PasswordResetService;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Facades\DB;

/**
 * A DPC committee member's portal login (business decision, 2026-11-18).
 *
 * One place for the two things the Administrator does to it, from User Management
 * or from the committee screen alike:
 *
 *  - create the login of a seat recorded without one — or link the account its
 *    e-mail and mobile already belong to, refusing when they disagree;
 *  - start a password reset. The member sets the password: the codes go to the
 *    member's own registered e-mail and mobile through the Forgot Password flow, and
 *    the Administrator never sees or chooses a password.
 */
final class CommitteeMemberAccounts
{
    public function __construct(
        private readonly DpcCommitteeService $committee,
        private readonly PasswordResetService $resets,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * Whether this account is a committee member's alone: it holds no portal role and
     * sits on a DPC committee. Such an account resets its own password.
     */
    public function isCommitteeOnly(User $user): bool
    {
        return $user->roles()->doesntExist()
            && DpcCommitteeMember::query()->where('user_id', $user->id)->exists();
    }

    /**
     * Create (or link) the login of a seat recorded without one. Nothing else on the
     * seat changes, and an existing account's password and roles are left as they are.
     *
     * @throws WorkflowException
     */
    public function createLogin(DpcCommitteeMember $seat, User $admin): User
    {
        if ($seat->user_id !== null) {
            throw new WorkflowException((string) __('dpc.committee.account.already_linked'));
        }

        if (blank($seat->email) || blank($seat->mobile)) {
            throw new WorkflowException((string) __('dpc.committee.account.contact_missing'));
        }

        return DB::transaction(function () use ($seat, $admin): User {
            /** @var DpcCommitteeMember $locked */
            $locked = DpcCommitteeMember::query()->whereKey($seat->id)->lockForUpdate()->firstOrFail();

            if ($locked->user_id !== null) {
                throw new WorkflowException((string) __('dpc.committee.account.already_linked'));
            }

            $user = $this->committee->resolveMember(['name' => $locked->name, 'email' => $locked->email, 'mobile' => $locked->mobile], $admin);

            if ($user === null) {
                throw new WorkflowException((string) __('dpc.committee.account.contact_missing'));
            }

            $locked->forceFill(['user_id' => $user->id])->save();

            $this->timeline->record('committee_member.account_linked', $locked, sprintf('Login of committee member %s linked', $locked->name), [
                'dpc_proposal_id' => $locked->dpc_proposal_id,
                'user_id' => $user->id,
            ], $admin);

            return $user;
        });
    }

    /**
     * Start a password reset for a committee member: the Forgot Password codes are
     * sent to the member's registered e-mail and mobile, and the member sets the
     * password. Recorded; no code or password is.
     *
     * @throws WorkflowException
     */
    public function initiateReset(User $member, User $admin): void
    {
        if (! $member->is_active) {
            throw new WorkflowException((string) __('dpc.committee.account.inactive'));
        }

        if (blank($member->email) || blank($member->mobile)) {
            throw new WorkflowException((string) __('dpc.committee.account.contact_missing'));
        }

        try {
            $this->resets->start($member);
        } catch (\RuntimeException $e) {
            // Throttled, or the gateway refused: said plainly, nothing recorded as sent.
            report($e);

            throw new WorkflowException((string) __('dpc.committee.account.reset_not_sent'), 'reset');
        }

        $this->timeline->record('password_reset.initiated_by_admin', $member, sprintf('Password reset started for %s; codes sent to the registered e-mail and mobile', $member->name), [
            'user_id' => $member->id,
        ], $admin);
    }
}
