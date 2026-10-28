<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\AccountSessions;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Identity\Exceptions\IdentityRefused;
use App\Domain\Identity\Models\IdentityVerification;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Enums\Role;
use App\Integrations\DigiLocker\DigiLockerIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Officer identity verification (Phases 2–4, 2026-10-27).
 *
 * Required of every Department Admin, Authorized Officer and Administrative Head
 * (`dpc.identity.roles`) before they reach any screen. Satisfied by a DigiLocker
 * match or, exceptionally, by a CGPSC Super Admin's manual verification; the two
 * give identical permissions and neither expires. The Super Admin can revoke
 * either, which ends the officer's sessions until they verify again.
 *
 * Three mismatches in 24 hours lock DigiLocker verification for the rest of that
 * window; a technical failure, a refused consent, or an incomplete record never
 * counts. Nothing written here or to the logs contains the identity values except
 * the encrypted `returned_identity`.
 */
class IdentityVerificationService
{
    public function __construct(
        private readonly IdentityMatcher $matcher,
        private readonly ActivityTimelineService $timeline,
        private readonly SecurityLog $log,
        private readonly AccountSessions $sessions,
        private readonly SupportingDocumentStore $documents,
    ) {}

    public function requiredFor(User $user): bool
    {
        if (! (bool) config('dpc.identity.required', true)) {
            return false;
        }

        return array_intersect($user->grantedRoleNames(), (array) config('dpc.identity.roles', [])) !== [];
    }

    public function isSatisfied(User $user): bool
    {
        return ! $this->requiredFor($user) || $user->identity_verified_at !== null;
    }

    /**
     * When the DigiLocker lock lifts, or null when the officer may try now.
     */
    public function lockedUntil(User $user): ?Carbon
    {
        $limit = (int) config('dpc.identity.lock_attempts', 3);
        $hours = (int) config('dpc.identity.lock_hours', 24);

        $recent = IdentityVerification::query()
            ->where('user_id', $user->id)
            ->where('outcome', IdentityVerification::OUTCOME_MISMATCH)
            ->where('created_at', '>', now()->subHours($hours))
            ->latest('id')
            ->limit($limit)
            ->pluck('created_at');

        if ($recent->count() < $limit) {
            return null;
        }

        /** @var Carbon $oldest */
        $oldest = Carbon::parse($recent->last());

        return $oldest->copy()->addHours($hours);
    }

    /**
     * Compare what DigiLocker returned with the record, and record the outcome.
     */
    public function recordResult(User $user, string $method, DigiLockerIdentity $identity): IdentityVerification
    {
        return DB::transaction(function () use ($user, $method, $identity): IdentityVerification {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($user->date_of_birth === null || $user->gender === null) {
                $outcome = IdentityVerification::OUTCOME_RECORD_INCOMPLETE;
                $fields = null;
            } elseif (! $identity->aadhaarVerified) {
                $outcome = IdentityVerification::OUTCOME_NOT_AADHAAR_VERIFIED;
                $fields = null;
            } else {
                $fields = $this->matcher->mismatches($user, $identity);
                $outcome = $fields === [] ? IdentityVerification::OUTCOME_VERIFIED : IdentityVerification::OUTCOME_MISMATCH;
            }

            $row = IdentityVerification::query()->create([
                'user_id' => $user->id,
                'method' => $method,
                'outcome' => $outcome,
                'mismatched_fields' => $fields ?: null,
                'returned_identity' => $identity->toArray(),
                'provider_reference' => $identity->reference,
                'ip_address' => request()->ip(),
            ]);

            if ($outcome === IdentityVerification::OUTCOME_VERIFIED) {
                $user->forceFill(['identity_verified_at' => now(), 'identity_verification_method' => $method])->save();
                $this->timeline->record('identity.verified', $user, "Identity verified through {$method}", ['verification' => $row->uuid], $user);
            } else {
                $this->timeline->record('identity.verification_failed', $user, "Identity verification: {$outcome}", ['verification' => $row->uuid, 'fields' => $fields], $user);
            }

            $this->log->record('identity.'.$outcome, $user);

            return $row;
        });
    }

    /**
     * A technical failure or a refused consent: recorded, never counted.
     */
    public function recordFailure(User $user, string $method, string $outcome, string $reason): IdentityVerification
    {
        $row = IdentityVerification::query()->create([
            'user_id' => $user->id,
            'method' => $method,
            'outcome' => $outcome,
            'reason' => mb_substr($reason, 0, 500),
            'ip_address' => request()->ip(),
        ]);
        $this->log->record('identity.'.$outcome, $user);

        return $row;
    }

    /**
     * The CGPSC Super Admin verifies an officer who cannot complete DigiLocker
     * (exceptional): a reason and a supporting document, recorded in full.
     */
    public function verifyManually(User $officer, User $admin, string $reason, UploadedFile $document): IdentityVerification
    {
        $this->assertSuperAdmin($admin);

        if ($officer->date_of_birth === null || $officer->gender === null) {
            throw new IdentityRefused((string) __('identity.errors.record_incomplete'));
        }

        return DB::transaction(function () use ($officer, $admin, $reason, $document): IdentityVerification {
            $row = IdentityVerification::query()->create([
                'user_id' => $officer->id,
                'method' => 'manual',
                'outcome' => IdentityVerification::OUTCOME_MANUAL,
                'reason' => $reason,
                'decided_by' => $admin->id,
                'ip_address' => request()->ip(),
            ] + $this->documents->store($document, 'identity/'.$officer->uuid));

            $officer->forceFill(['identity_verified_at' => now(), 'identity_verification_method' => 'manual'])->save();
            $this->timeline->record('identity.manually_verified', $officer, 'Identity manually verified by the CGPSC Super Admin', ['verification' => $row->uuid], $admin);
            $this->log->record('identity.manually_verified', $officer, actor: $admin);

            return $row;
        });
    }

    /**
     * Withdraw the current verification (forced re-verification, or a correction
     * of verified details): the officer's sessions end and every screen stays
     * closed until they verify again. Past DPC approvals are untouched.
     */
    public function revoke(User $officer, ?User $actor, string $reason): void
    {
        DB::transaction(function () use ($officer, $actor, $reason): void {
            IdentityVerification::query()
                ->where('user_id', $officer->id)
                ->whereIn('outcome', [IdentityVerification::OUTCOME_VERIFIED, IdentityVerification::OUTCOME_MANUAL])
                ->whereNull('revoked_at')
                ->get()
                ->each(fn (IdentityVerification $row) => $row->forceFill([
                    'revoked_at' => now(), 'revoked_by' => $actor?->id, 'revoke_reason' => $reason,
                ])->save());

            $officer->forceFill(['identity_verified_at' => null, 'identity_verification_method' => null])->save();
            $this->timeline->record('identity.revoked', $officer, 'Identity verification withdrawn: '.$reason, [], $actor);
            $this->log->record('identity.revoked', $officer, actor: $actor);
            $this->sessions->invalidate($officer, 'identity_revoked', $actor);
        });
    }

    public function forceReverification(User $officer, User $admin, string $reason): void
    {
        $this->assertSuperAdmin($admin);

        if ($officer->identity_verified_at === null) {
            throw new IdentityRefused((string) __('identity.errors.not_verified'));
        }

        $this->revoke($officer, $admin, $reason);
    }

    public function latestMismatch(User $user): ?IdentityVerification
    {
        return IdentityVerification::query()
            ->where('user_id', $user->id)
            ->where('outcome', IdentityVerification::OUTCOME_MISMATCH)
            ->latest('id')
            ->first();
    }

    public function statusLabel(User $user): string
    {
        if (! $this->requiredFor($user) && $user->identity_verified_at === null) {
            return (string) __('identity.status.not_required');
        }

        return match ($user->identity_verification_method) {
            'manual' => (string) __('identity.status.manually_verified'),
            'digilocker', 'digilocker_simulated' => (string) __('identity.status.verified'),
            default => (string) __('identity.status.pending'),
        };
    }

    private function assertSuperAdmin(User $admin): void
    {
        if (! $admin->hasRole(Role::Admin->value)) {
            throw new IdentityRefused((string) __('identity.errors.super_admin_only'));
        }
    }
}
