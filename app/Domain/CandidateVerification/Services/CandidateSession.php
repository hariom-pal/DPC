<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * A candidate's signed-in session on the Candidate Portal (2026-11-19; one portal
 * since 2026-11-20). Candidates hold no staff session: the OTP proves the registered
 * mobile and the eSign the name, and the session holds the Seniority List rows that
 * mobile (and, where it is shared, the Employee ID) leads to — every DPC of theirs the
 * portal shows, with the verification of each where there is one — and nothing else.
 * It ends after the configured minutes without use.
 */
final class CandidateSession
{
    /**
     * The DPCs the portal shows: from the AH's approval on — the candidates'
     * verification, the AH's review of their grievances, the Secretary, and every
     * stage after, concluded ones included. A draft, a DPC with its AH or one returned
     * for correction is still the department's; a cancelled one is gone.
     */
    public const VISIBLE = [
        'candidate_verify',
        'grievance_review',
        'pending_secretary',
        'approved',
    ];

    private const KEY = 'candidate_verification';

    private const PENDING = 'candidate_verification_pending';

    private const SIGN = 'candidate_verification_sign_in';

    /** @param list<int> $rowIds */
    public function start(Request $request, array $rowIds): void
    {
        $request->session()->forget([self::PENDING, self::SIGN]);
        $request->session()->regenerate();
        $request->session()->put(self::KEY, ['rows' => $rowIds, 'seen' => now()->timestamp]);
    }

    /**
     * Whether a candidate is signed in on this session, for the public pages' header
     * (2026-11-20). Reads only: it neither extends the session nor queries anything.
     */
    public function signedIn(Request $request): bool
    {
        $state = $request->hasSession() ? $request->session()->get(self::KEY) : null;
        $minutes = max(1, (int) config('dpc.candidate_verification.session_minutes', 30));

        return is_array($state) && isset($state['rows'], $state['seen']) && now()->timestamp - (int) $state['seen'] <= $minutes * 60;
    }

    /** @return Collection<int, DpcEvaluationRow>|null the candidate's own rows, or null when not signed in */
    public function rows(Request $request): ?Collection
    {
        $state = $request->session()->get(self::KEY);
        $minutes = max(1, (int) config('dpc.candidate_verification.session_minutes', 30));

        if (! is_array($state) || ! isset($state['rows'], $state['seen']) || now()->timestamp - (int) $state['seen'] > $minutes * 60) {
            // The signed-in state only: a sign-in still under way stays.
            $request->session()->forget(self::KEY);

            return null;
        }

        $request->session()->put(self::KEY.'.seen', now()->timestamp);

        return DpcEvaluationRow::query()->whereIn('id', array_map('intval', (array) $state['rows']))
            ->whereHas('proposal', fn ($q) => $q->whereIn('status', self::VISIBLE))
            ->with(['proposal.department', 'proposal.promotionToPost', 'proposal.promotionFromPost'])
            ->get()
            ->sortByDesc(fn (DpcEvaluationRow $row): string => sprintf('%04d-%010d', (int) $row->proposal->dpc_year, (int) $row->proposal->id))
            ->values();
    }

    /** One of the candidate's own rows, or null — never another officer's. */
    public function row(Request $request, int $id): ?DpcEvaluationRow
    {
        return $this->rows($request)?->firstWhere('id', $id);
    }

    /** @return Collection<int, VerificationParticipant>|null the candidate's verifications, or null when not signed in */
    public function participants(Request $request): ?Collection
    {
        $rows = $this->rows($request);
        if ($rows === null) {
            return null;
        }

        return VerificationParticipant::query()->whereIn('dpc_evaluation_row_id', $rows->pluck('id')->all())
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
     * @param  list<int>  $rowIds
     */
    public function pending(Request $request, array $rowIds): void
    {
        $request->session()->put(self::PENDING, ['rows' => $rowIds, 'until' => now()->addMinutes(5)->timestamp]);
    }

    /** @return list<int>|null */
    public function pendingRows(Request $request): ?array
    {
        return $this->held($request, self::PENDING);
    }

    /**
     * The officer known — mobile, OTP and, where needed, Employee ID — awaiting the
     * eSign that checks their name (ten minutes).
     *
     * @param  list<int>  $rowIds
     */
    public function awaitSignature(Request $request, array $rowIds): void
    {
        $request->session()->forget(self::PENDING);
        $request->session()->put(self::SIGN, ['rows' => $rowIds, 'until' => now()->addMinutes(10)->timestamp]);
    }

    /** @return list<int>|null */
    public function signatureRows(Request $request): ?array
    {
        return $this->held($request, self::SIGN);
    }

    public function end(Request $request): void
    {
        $request->session()->forget([self::KEY, self::PENDING, self::SIGN]);
    }

    /** @return list<int>|null */
    private function held(Request $request, string $key): ?array
    {
        $state = $request->session()->get($key);

        return is_array($state) && (int) ($state['until'] ?? 0) >= now()->timestamp ? array_values(array_map('intval', (array) $state['rows'])) : null;
    }
}
