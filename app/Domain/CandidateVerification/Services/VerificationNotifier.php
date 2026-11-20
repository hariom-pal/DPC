<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Jobs\SendVerificationNotification;
use App\Domain\CandidateVerification\Models\VerificationCycle;
use App\Domain\CandidateVerification\Models\VerificationNotification;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use Illuminate\Database\QueryException;

/**
 * The verification's messages (2026-11-19), each recorded before it is queued: once
 * only (cycle, kind, channel, recipient, reference), skipped where there is no number
 * or address, and sent or failed by the queued job — queued is never "delivered".
 * The texts are the Administrator's templates (candidate_verification.*), which carry
 * no CR, Integrity or Property File information.
 */
final class VerificationNotifier
{
    public const SMS = 'sms';

    public const EMAIL = 'email';

    public function __construct(private readonly CandidateAccounts $accounts) {}

    /** @param array<string, string> $values */
    public function toParticipant(VerificationParticipant $participant, string $kind, array $values, string $reference = ''): void
    {
        $row = $participant->row;
        $recipient = 'participant:'.$participant->id;
        $cycle = $participant->cycle;

        $this->queue($cycle, $kind, self::SMS, $recipient, $reference, $row->mobile, $values);
        $this->queue($cycle, $kind, self::EMAIL, $recipient, $reference, $this->accounts->userFor($row)?->email, $values);
    }

    /**
     * @param  iterable<User>  $users
     * @param  array<string, string>  $values
     */
    public function toUsers(VerificationCycle $cycle, iterable $users, string $kind, array $values, string $reference = ''): void
    {
        foreach ($users as $user) {
            $recipient = 'user:'.$user->id;
            $this->queue($cycle, $kind, self::SMS, $recipient, $reference, $user->mobile, $values);
            $this->queue($cycle, $kind, self::EMAIL, $recipient, $reference, $user->email, $values);
        }
    }

    /** @param array<string, string> $values */
    private function queue(VerificationCycle $cycle, string $kind, string $channel, string $recipient, string $reference, ?string $destination, array $values): void
    {
        $destination = trim((string) $destination) === '' ? null : trim((string) $destination);

        try {
            $record = VerificationNotification::query()->create([
                'dpc_verification_cycle_id' => $cycle->id,
                'kind' => $kind,
                'channel' => $channel,
                'recipient' => $recipient,
                'reference' => $reference,
                'destination' => $destination,
                'status' => $destination === null ? VerificationNotification::SKIPPED : VerificationNotification::QUEUED,
                'last_error' => $destination === null ? 'No '.($channel === self::SMS ? 'mobile number' : 'e-mail address').' on record.' : null,
            ]);
        } catch (QueryException) {
            // Already recorded for this recipient: once only, never twice.
            return;
        }

        if ($destination !== null) {
            SendVerificationNotification::dispatch($record->id, $values)->afterCommit();
        }
    }
}
