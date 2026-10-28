<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Identity\Exceptions\IdentityRefused;
use App\Domain\Identity\Models\IdentityCorrection;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Corrections to an officer's name, date of birth or gender (Phases 3–4,
 * 2026-10-27), always with a reason and a field-level history.
 *
 * - **Before verification** the creator corrects them: the Department Admin for the
 *   department's AO/AH, the CGPSC Super Admin for anyone (a Department Admin's
 *   mismatch is the Super Admin's).
 * - **After verification** the three fields are locked: only the CGPSC Super Admin
 *   may correct them, with a supporting document, and the correction withdraws the
 *   verification — sessions end and every screen stays closed until the officer
 *   verifies again. Past approvals are not touched.
 *
 * Designation, e-mail and mobile are not identity fields and never come here.
 */
class IdentityCorrectionService
{
    public const FIELDS = ['name', 'date_of_birth', 'gender'];

    public function __construct(
        private readonly IdentityVerificationService $verification,
        private readonly SupportingDocumentStore $documents,
        private readonly ActivityTimelineService $timeline,
        private readonly SecurityLog $log,
    ) {}

    /**
     * The identity fields that would change.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public function changes(User $officer, array $values): array
    {
        $changes = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $old = $this->value($officer, $field);
            $new = $values[$field] === null || $values[$field] === '' ? null : (string) $values[$field];

            if ($field === 'date_of_birth' && $new !== null) {
                $new = Carbon::parse($new)->toDateString();
            }
            if ($field === 'name' && $new !== null) {
                $new = trim($new);
            }

            if ($old !== $new) {
                $changes[$field] = [$old, $new];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function correct(User $officer, array $values, string $reason, User $actor, ?UploadedFile $document = null): void
    {
        $changes = $this->changes($officer, $values);

        if ($changes === []) {
            return;
        }

        $verified = $officer->identity_verified_at !== null;
        $superAdmin = $actor->hasRole(Role::Admin->value);

        if ($verified && ! $superAdmin) {
            throw new IdentityRefused((string) __('identity.errors.locked'));
        }

        if ($verified && $document === null) {
            throw new IdentityRefused((string) __('identity.errors.document_required'));
        }

        if (trim($reason) === '') {
            throw new IdentityRefused((string) __('identity.errors.reason_required'));
        }

        DB::transaction(function () use ($officer, $changes, $reason, $actor, $document, $verified): void {
            $stored = $document === null ? [] : $this->documents->store($document, 'identity/'.$officer->uuid);

            foreach ($changes as $field => [$old, $new]) {
                IdentityCorrection::query()->create([
                    'user_id' => $officer->id,
                    'stage' => $verified ? IdentityCorrection::STAGE_VERIFIED : IdentityCorrection::STAGE_PRE_VERIFICATION,
                    'field' => $field,
                    'old_value' => $old,
                    'new_value' => $new,
                    'reason' => $reason,
                    'corrected_by' => $actor->id,
                ] + $stored);
            }

            $officer->forceFill(array_map(fn (array $pair): ?string => $pair[1], $changes))->save();
            $this->timeline->record('identity.corrected', $officer, 'Identity details corrected: '.implode(', ', array_keys($changes)), ['fields' => array_keys($changes), 'verified' => $verified], $actor);
            // Field names only: the old and new values stay in identity_corrections.
            $this->log->record('identity.corrected', $officer, context: ['fields' => implode(',', array_keys($changes)), 'verified' => $verified], actor: $actor);

            if ($verified) {
                $this->verification->revoke($officer, $actor, 'Verified identity corrected: '.$reason);
            }
        });
    }

    private function value(User $officer, string $field): ?string
    {
        return match ($field) {
            'date_of_birth' => $officer->date_of_birth?->toDateString(),
            default => $officer->{$field} === null ? null : (string) $officer->{$field},
        };
    }
}
