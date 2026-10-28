<?php

declare(strict_types=1);

namespace App\Domain\Signature\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Signature\Models\SignatureAttempt;
use Illuminate\Database\Eloquent\Model;

/**
 * The record of verified eSign attempts and the open "eSign issue" of a
 * proposal (2026-10-28).
 *
 * An issue is open while the latest attempt at the step the proposal is waiting
 * on failed: the AO's submission while it is a draft or returned, the AH's
 * approval while it is submitted. The next successful attempt closes it.
 */
class SignatureAttemptLog
{
    public function record(User $signer, Model $signable, string $action, string $outcome, ?string $assertedName = null): SignatureAttempt
    {
        return SignatureAttempt::query()->create([
            'signable_type' => $signable->getMorphClass(),
            'signable_id' => $signable->getKey(),
            'user_id' => $signer->id,
            'action' => $action,
            'outcome' => $outcome,
            'asserted_name' => $assertedName,
            'portal_name' => $signer->name,
        ]);
    }

    public function openIssue(PreDpcProposal|DpcProposal $proposal): ?SignatureAttempt
    {
        $action = match (true) {
            $proposal->status->isEditable() => 'submit',
            $proposal->status->value === 'submitted' => 'approve',
            default => null,
        };

        if ($action === null) {
            return null;
        }

        $latest = SignatureAttempt::query()->with('user')
            ->where('signable_type', $proposal->getMorphClass())
            ->where('signable_id', $proposal->getKey())
            ->where('action', $action)
            ->latest('id')
            ->first();

        return $latest !== null && $latest->failed() ? $latest : null;
    }

    /**
     * The proposals among these with an open issue.
     *
     * @param  iterable<PreDpcProposal|DpcProposal>  $proposals
     * @return list<int>
     */
    public function withOpenIssues(iterable $proposals): array
    {
        $ids = [];

        foreach ($proposals as $proposal) {
            if ($this->openIssue($proposal) !== null) {
                $ids[] = (int) $proposal->getKey();
            }
        }

        return $ids;
    }
}
