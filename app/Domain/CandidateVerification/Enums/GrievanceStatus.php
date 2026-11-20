<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Enums;

enum GrievanceStatus: string
{
    /** Being written by the candidate; changes or goes until the final submission. */
    case Draft = 'draft';
    /** Signed in the final submission, awaiting the AH. */
    case Submitted = 'submitted';
    /** The AH opened the candidate's section for the AO to correct. */
    case SentToAo = 'sent_to_ao';
    /** The AO's correction is back with the AH. */
    case ReturnedFromAo = 'returned_from_ao';
    /** The AH's final decision is recorded. */
    case Resolved = 'resolved';

    public function label(): string
    {
        return (string) __('candidate.grievance.status.'.$this->value);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::SentToAo, self::ReturnedFromAo], true);
    }
}
