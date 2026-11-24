<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * Where a DPC stands and whose move it is (MoM of 16 November 2026, points 5, 6 and
 * 8): the Authorized Officer preparing it, the Administrative Head verifying and
 * approving it, the Secretary, CGPSC, the Chairman, CGPSC allotting it, and the DPC
 * Committee evaluating it. Each stage has its own timeline, set by the CGPSC
 * Administrator.
 */
enum DpcStage: string
{
    case Ao = 'ao';
    case Ah = 'ah';
    case Secretary = 'secretary';
    case Chairman = 'chairman';
    case Committee = 'committee';

    /**
     * Candidate Verification & Grievance Submission (2026-11-19): after the AH's approval,
     * the candidates verify their own records and may raise grievances, until the
     * deadline the Administrator's days give. Its expiry is the verification's own
     * routing (dpc:candidate-verification-deadlines), never a cancel or a forward.
     */
    case CandidateVerification = 'candidate_verification';

    /**
     * The DPC Chairman opens the allotted DPC to the committee members (2026-11-24):
     * these days after the allotment; past them it opens to them on its own. Not a
     * stage a DPC stands at — it runs beside the Committee's.
     */
    case CommitteeView = 'committee_view';

    public function label(): string
    {
        return (string) __('dpc.stage.'.$this->value.'.label');
    }

    /** What is to be done at this stage. */
    public function action(): string
    {
        return (string) __('dpc.stage.'.$this->value.'.action');
    }

    /**
     * What may happen once the stage's days have passed (2026-11-17). Forwarding
     * is offered only where the next step needs no one's choice or signature: the
     * AH's approval to the Secretary, and the Secretary's to the Chairman. The AO's
     * submission needs the AO's eSign, the Chairman's allotment a committee member,
     * and nothing follows the committee.
     *
     * @return list<string>
     */
    public function expiryActions(): array
    {
        return match ($this) {
            self::Ah, self::Secretary => ['notify', 'cancel', 'forward'],
            self::Ao, self::Chairman => ['notify', 'cancel'],
            self::Committee, self::CandidateVerification, self::CommitteeView => ['notify'],
        };
    }

    /** The stage the DPC goes to next, or null after the committee. */
    public function next(): ?self
    {
        return match ($this) {
            self::Ao => self::Ah,
            self::Ah => config('dpc.candidate_verification.enabled', true) ? self::CandidateVerification : self::Secretary,
            self::CandidateVerification => self::Secretary,
            self::Secretary => self::Chairman,
            self::Chairman => self::Committee,
            self::CommitteeView => self::Committee,
            self::Committee => null,
        };
    }
}
