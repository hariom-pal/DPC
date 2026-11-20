<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\CandidateVerification\Models\VerificationParticipant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * A candidate's signed-in session (2026-11-19). Candidates hold no staff session: the
 * OTP proves the registered mobile, and the session holds the verifications that
 * mobile (and, where it is shared, the Employee ID) leads to — nothing else. It ends
 * after the configured minutes without use.
 */
final class CandidateSession
{
    private const KEY = 'candidate_verification';

    private const PENDING = 'candidate_verification_pending';

    /** @param list<int> $participantIds */
    public function start(Request $request, array $participantIds): void
    {
        $request->session()->forget(self::PENDING);
        $request->session()->regenerate();
        $request->session()->put(self::KEY, ['participants' => $participantIds, 'seen' => now()->timestamp]);
    }

    /** @return Collection<int, VerificationParticipant>|null the candidate's verifications, or null when not signed in */
    public function participants(Request $request): ?Collection
    {
        $state = $request->session()->get(self::KEY);
        $minutes = max(1, (int) config('dpc.candidate_verification.session_minutes', 30));

        if (! is_array($state) || ! isset($state['participants'], $state['seen']) || now()->timestamp - (int) $state['seen'] > $minutes * 60) {
            // The signed-in state only: an Employee ID still being asked for stays.
            $request->session()->forget(self::KEY);

            return null;
        }

        $request->session()->put(self::KEY.'.seen', now()->timestamp);

        return VerificationParticipant::query()->whereIn('id', (array) $state['participants'])
            ->with(['cycle', 'row.proposal.department', 'row.proposal.promotionToPost'])->orderByDesc('id')->get();
    }

    /** One of the candidate's own verifications by its UUID, or null — never another officer's. */
    public function participant(Request $request, string $uuid): ?VerificationParticipant
    {
        return $this->participants($request)?->firstWhere('uuid', $uuid);
    }

    /**
     * The mobile proved, awaiting the Employee ID because it is shared (five minutes).
     *
     * @param  list<int>  $participantIds
     */
    public function pending(Request $request, array $participantIds): void
    {
        $request->session()->put(self::PENDING, ['participants' => $participantIds, 'until' => now()->addMinutes(5)->timestamp]);
    }

    /** @return list<int>|null */
    public function pendingParticipants(Request $request): ?array
    {
        $state = $request->session()->get(self::PENDING);

        return is_array($state) && (int) ($state['until'] ?? 0) >= now()->timestamp ? array_map('intval', (array) $state['participants']) : null;
    }

    public function end(Request $request): void
    {
        $request->session()->forget([self::KEY, self::PENDING]);
    }
}
