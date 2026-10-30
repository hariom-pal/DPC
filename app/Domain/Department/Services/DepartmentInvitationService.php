<?php

declare(strict_types=1);

namespace App\Domain\Department\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\AccountSessions;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Department\Exceptions\InvitationRefused;
use App\Domain\Department\IssuedInvitation;
use App\Domain\Department\Models\Department;
use App\Domain\Department\Models\DepartmentInvitation;
use App\Domain\Identity\Models\IdentityCorrection;
use App\Domain\Identity\Models\IdentityVerification;
use App\Domain\Identity\Services\IdentityVerificationService;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Domain\UserManagement\Services\DepartmentAssignmentService;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use App\Integrations\DigiLocker\DigiLockerIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * Department Admin registration by invitation (2026-10-26; DigiLocker 2026-10-27; department + e-mail only 2026-10-28).
 *
 * Only the Portal Super Admin invites. The link is sent to one official e-mail and
 * is the only way to register: there is no public form that lists departments, so
 * nobody can claim a department they were not invited to. The token is never
 * stored — only its SHA-256 — and is consumed once, expires, and can be revoked.
 *
 * The e-mail is sent synchronously so an SMTP failure is reported to the Portal
 * Super Admin at once; the invitation stays and can be resent.
 */
class DepartmentInvitationService
{
    public function __construct(
        private readonly DepartmentAssignmentService $assignments,
        private readonly AccountSessions $sessions,
        private readonly IdentityVerificationService $identities,
        private readonly ActivityTimelineService $timeline,
        private readonly SecurityLog $log,
    ) {}

    /**
     * Invite the department's Department Admin (2026-10-28): the department and
     * its own official e-mail — the one in the Department Master, never a
     * personal one, and never another department's. One Department Admin per
     * department: the person who accepts replaces whoever held it. Both OTPs are
     * always required.
     *
     * @throws InvitationRefused
     */
    public function invite(Department $department, string $email, User $actor): IssuedInvitation
    {
        $email = mb_strtolower(trim($email));
        $this->assertDepartmentEmail($department, $email);

        return DB::transaction(function () use ($department, $email, $actor): IssuedInvitation {
            // The department row orders two invitations sent at once.
            Department::query()->lockForUpdate()->findOrFail($department->id);

            if (DepartmentInvitation::query()->pending()->where('department_id', $department->id)->exists()) {
                throw new InvitationRefused((string) __('invitations.errors.already_pending'));
            }

            $token = Str::random(64);

            $invitation = DepartmentInvitation::query()->create([
                'department_id' => $department->id,
                'email' => $email,
                'token_hash' => DepartmentInvitation::hashToken($token),
                'expires_at' => now()->addHours($this->ttlHours()),
                'invited_by' => $actor->id,
                'mobile_otp_waived' => false,
            ]);

            $this->timeline->record('department.invitation_issued', $department, "Department Admin invitation issued to {$email}", causer: $actor);

            return $this->issue($invitation, $token);
        });
    }

    /**
     * The e-mail must be the department's own (2026-10-28): refused before any
     * invitation exists, naming the department it belongs to when it is another's.
     *
     * @throws InvitationRefused
     */
    public function assertDepartmentEmail(Department $department, string $email): void
    {
        $email = mb_strtolower(trim($email));

        if (! $department->hasRegistrationDetails()) {
            throw new InvitationRefused((string) __('invitations.errors.department_incomplete'));
        }

        $owner = Department::query()->whereKeyNot($department->id)->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($owner !== null) {
            throw new InvitationRefused((string) __('invitations.errors.other_department_email', ['department' => $owner->name]));
        }

        if ($email !== mb_strtolower(trim((string) $department->email))) {
            throw new InvitationRefused((string) __('invitations.errors.not_department_email', ['email' => (string) $department->email]));
        }

        $account = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($account !== null && ! $this->isDepartmentAccount($account)) {
            throw new InvitationRefused((string) __('invitations.errors.email_in_use'));
        }
    }

    /**
     * Send again with a NEW token and a new expiry; the previous link stops working.
     */
    public function resend(DepartmentInvitation $invitation, User $actor): IssuedInvitation
    {
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw new InvitationRefused((string) __('invitations.errors.not_pending'));
        }

        $token = Str::random(64);
        $invitation->forceFill([
            'token_hash' => DepartmentInvitation::hashToken($token),
            'expires_at' => now()->addHours($this->ttlHours()),
        ])->save();

        $this->timeline->record('department.invitation_resent', $invitation->department, "Department Admin invitation re-sent to {$invitation->email}", causer: $actor);

        return $this->issue($invitation, $token);
    }

    public function revoke(DepartmentInvitation $invitation, User $actor): void
    {
        if ($invitation->accepted_at !== null) {
            throw new InvitationRefused((string) __('invitations.errors.not_pending'));
        }

        $invitation->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();
        $this->timeline->record('department.invitation_revoked', $invitation->department, "Department Admin invitation to {$invitation->email} revoked", causer: $actor);
    }

    /**
     * The invitation a link refers to, if it is still usable.
     */
    public function usable(string $token): ?DepartmentInvitation
    {
        $invitation = DepartmentInvitation::findByToken($token);

        return $invitation !== null && $invitation->isPending() ? $invitation : null;
    }

    /**
     * Register the invited person (2026-10-27; 2026-10-28) once the department's
     * e-mail and their mobile are proved by OTP and DigiLocker has returned their
     * identity. The department's account is created — or, when the department
     * already has one, handed to this person: its name, date of birth, gender,
     * mobile and password become theirs, the previous identity stays on record,
     * and the previous holder's sessions end. Either way it becomes the
     * department's only Department Admin.
     *
     * @throws InvitationRefused
     */
    public function register(
        DepartmentInvitation $invitation,
        string $mobile,
        DigiLockerIdentity $identity,
        string $method,
        #[SensitiveParameter] string $password,
        ?int $designationId = null,
    ): User {
        return DB::transaction(function () use ($invitation, $mobile, $identity, $method, $password, $designationId): User {
            $invitation = $this->lockPending($invitation);

            if (! $identity->aadhaarVerified) {
                throw new InvitationRefused((string) __('identity.outcome.not_aadhaar_verified'));
            }

            $account = User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])->lockForUpdate()->first();

            if ($account !== null && ! $this->isDepartmentAccount($account)) {
                throw new InvitationRefused((string) __('invitations.errors.email_in_use'));
            }

            if (User::query()->where('mobile', $mobile)->when($account !== null, fn ($q) => $q->whereKeyNot($account->id))->exists()) {
                throw new InvitationRefused((string) __('invitations.errors.mobile_taken'));
            }

            $user = $account ?? new User(['email' => $invitation->email]);

            if ($account !== null) {
                $this->handOver($account, $invitation, $identity);
            }

            $user->forceFill([
                'name' => $identity->name,
                'mobile' => $mobile,
                'date_of_birth' => $identity->dateOfBirth->toDateString(),
                'gender' => $identity->gender,
                'is_active' => true,
                'must_change_password' => false,
                'email_verified_at' => now(),
                'mobile_verified_at' => now(),
                'password_changed_at' => now(),
                'identity_verified_at' => now(),
                'identity_verification_method' => $method,
            ]);
            // The post the Department Admin chose (MoM of 2026-10-29).
            if ($designationId !== null) {
                $user->designation_id = $designationId;
            }
            $user->password = Hash::make($password);
            $user->save();

            IdentityVerification::query()->create([
                'user_id' => $user->id,
                'method' => $method,
                'outcome' => IdentityVerification::OUTCOME_VERIFIED,
                'returned_identity' => $identity->toArray(),
                'provider_reference' => $identity->reference,
                'ip_address' => request()->ip(),
            ]);

            $this->grant($invitation, $user);
            DB::afterCommit(fn () => $this->confirm($user, $invitation->department));

            return $user;
        });
    }

    /**
     * The department's account passes to the newly invited person: the previous
     * identity is recorded field by field, its verification withdrawn, and every
     * session of the previous holder ended.
     */
    private function handOver(User $account, DepartmentInvitation $invitation, DigiLockerIdentity $identity): void
    {
        $new = ['name' => $identity->name, 'date_of_birth' => $identity->dateOfBirth->toDateString(), 'gender' => $identity->gender];
        $old = ['name' => $account->name, 'date_of_birth' => $account->date_of_birth?->toDateString(), 'gender' => $account->gender];

        foreach ($new as $field => $value) {
            if ($old[$field] !== $value) {
                IdentityCorrection::query()->create([
                    'user_id' => $account->id,
                    'stage' => IdentityCorrection::STAGE_HANDOVER,
                    'field' => $field,
                    'old_value' => $old[$field],
                    'new_value' => $value,
                    'reason' => 'Department Admin re-invited by the CGPSC',
                    'corrected_by' => $invitation->invited_by,
                ]);
            }
        }

        $this->identities->revoke($account, $invitation->inviter, 'Department account handed to a new Department Admin');
        $this->timeline->record('department.admin_handed_over', $invitation->department, 'The Department Admin account was handed to a newly invited person', ['invitation' => $invitation->uuid], $invitation->inviter);
    }

    /**
     * An account the department's invitation may create or take over: one that
     * holds nothing but the Department Admin role, if anything.
     */
    private function isDepartmentAccount(User $account): bool
    {
        return array_diff($account->grantedRoleNames(), [Role::DepartmentAdmin->value]) === [];
    }

    private function lockPending(DepartmentInvitation $invitation): DepartmentInvitation
    {
        $invitation = DepartmentInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

        if (! $invitation->isPending()) {
            throw new InvitationRefused((string) __('invitations.errors.not_pending'));
        }

        return $invitation;
    }

    /**
     * The department's Department Admin is this account, and nobody else
     * (2026-10-28): every other Department Admin of the department loses the
     * posting at once, their sessions end, and an account left with no posting is
     * deactivated.
     */
    private function grant(DepartmentInvitation $invitation, User $user): void
    {
        $department = $invitation->department;
        $today = Carbon::today();

        $isAdminHere = UserDepartmentAssignment::query()->current()
            ->where('user_id', $user->id)->where('department_id', $department->id)->where('role', Role::DepartmentAdmin->value)->exists();

        if (! $isAdminHere) {
            $this->assignments->assign($user, $department, Role::DepartmentAdmin, remarks: 'Registered by invitation as Department Admin.');
        }

        $others = UserDepartmentAssignment::query()->current()->with('user')
            ->where('department_id', $department->id)->where('role', Role::DepartmentAdmin->value)
            ->where('user_id', '!=', $user->id)
            ->get();

        foreach ($others as $previous) {
            $this->assignments->end($previous, $today, 'Replaced by a new Department Admin invited by the CGPSC', $invitation->inviter);
            $this->sessions->invalidate($previous->user, 'department_admin_replaced', $invitation->inviter);

            if (! UserDepartmentAssignment::query()->current()->where('user_id', $previous->user_id)->exists()) {
                $previous->user->forceFill(['is_active' => false])->save();
                $this->log->record('account.deactivated', $previous->user, context: ['reason' => 'department_admin_replaced'], actor: $invitation->inviter);
            }
        }

        if ($department->status === DepartmentStatus::Unregistered) {
            $department->forceFill(['status' => DepartmentStatus::Registered->value, 'registered_at' => now()])->save();
        }

        $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
        $this->timeline->record('department.admin_registered', $department, "{$user->name} registered as Department Admin", ['invitation' => $invitation->uuid], $user);
        $this->log->record('invitation.accepted', $user, context: ['department_id' => $department->id]);
    }

    /**
     * E-mail the link, and hand it back for the one-time screen whether or not the
     * mail went (2026-10-26): a mail failure is recorded and shown, and no longer
     * leaves the Administrator without a way to deliver the link.
     */
    private function issue(DepartmentInvitation $invitation, #[SensitiveParameter] string $token): IssuedInvitation
    {
        $link = route('register.invitation', ['token' => $token]);

        return new IssuedInvitation($invitation, $link, $this->send($invitation, $link));
    }

    private function send(DepartmentInvitation $invitation, #[SensitiveParameter] string $link): bool
    {
        $message = (string) __('invitations.mail.body', [
            'department' => $invitation->department->name,
            'link' => $link,
            'hours' => $this->ttlHours(),
        ]);

        try {
            Mail::raw($message, function ($mail) use ($invitation): void {
                $mail->to($invitation->email)->subject((string) __('invitations.mail.subject'));
            });
        } catch (Throwable $e) {
            $invitation->forceFill(['delivery_error' => mb_substr($e::class, 0, 500)])->save();
            Log::error('Department Admin invitation could not be sent.', ['invitation' => $invitation->uuid, 'error' => $e::class]);

            return false;
        }

        $invitation->forceFill([
            'last_sent_at' => now(),
            'send_count' => $invitation->send_count + 1,
            'delivery_error' => null,
        ])->save();

        return true;
    }

    private function confirm(User $user, Department $department): void
    {
        try {
            Mail::raw((string) __('invitations.mail.registered_body', ['department' => $department->name, 'url' => route('login')]), function ($mail) use ($user): void {
                $mail->to((string) $user->email)->subject((string) __('invitations.mail.registered_subject'));
            });
        } catch (Throwable $e) {
            Log::warning('Department Admin registration confirmation could not be sent.', ['user' => $user->id, 'error' => $e::class]);
        }
    }

    private function ttlHours(): int
    {
        return max(1, (int) config('dpc.accounts.invitation_ttl_hours', 72));
    }
}
