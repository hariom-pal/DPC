<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Exceptions\SignatureVerificationFailed;
use App\Domain\Signature\Services\SignatureAttemptLog;
use App\Domain\Signature\Services\SignatureService;

/**
 * The last step of a candidate's sign-in to the Candidate Portal (2026-11-20): after
 * the mobile and the OTP, the candidate signs with eSign and the name on the
 * certificate is checked against their portal account — the Promotion Candidate the
 * Seniority List provisioned, found by Employee ID within the DPC's department. Only
 * then does the portal open. Every sign-in is its own signature, kept with the
 * others; a refused name is logged as an attempt, like every refused eSign.
 */
final class CandidateSignIn
{
    /** The action in signature_attempts (a string(20)). */
    public const SIGN_ACTION = 'candidate_login';

    public function __construct(
        private readonly CandidateAccounts $accounts,
        private readonly SignatureService $signatures,
    ) {}

    /**
     * @param  list<int>  $rowIds  one officer's rows (one identity)
     *
     * @throws WorkflowException when there is no account to sign with or the eSign fails
     */
    public function sign(array $rowIds): User
    {
        $rows = DpcEvaluationRow::query()->whereIn('id', $rowIds)->with('proposal')->orderByDesc('id')->get();

        $signer = null;
        $signable = null;
        foreach ($rows as $row) {
            if (($account = $this->accounts->userFor($row)) !== null) {
                [$signer, $signable] = [$account, $row];
                break;
            }
        }

        if ($signer === null || $signable === null) {
            throw new WorkflowException((string) __('candidate.verification.errors.no_account'));
        }

        $document = implode("\n", [
            'CANDIDATE PORTAL — SIGN-IN',
            'Name: '.$signable->name,
            'Employee ID: '.$signable->employee_number,
            'Signed in: '.now()->toIso8601String(),
        ]);

        try {
            $this->signatures->signVerified($signer, $signable, $document, 'candidate-portal-sign-in.txt', ['action' => self::SIGN_ACTION]);
        } catch (SignatureVerificationFailed $e) {
            app(SignatureAttemptLog::class)->record($signer, $signable, self::SIGN_ACTION, $e->reason, $e->assertedName);

            throw new WorkflowException($e->getMessage());
        }

        return $signer;
    }
}
