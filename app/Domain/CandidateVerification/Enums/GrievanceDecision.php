<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Enums;

/** The AH's decision on a grievance. */
enum GrievanceDecision: string
{
    /** The grievance stands: the record was (or is now) corrected. */
    case Upheld = 'upheld';
    /** The record stands as it was. */
    case NotUpheld = 'not_upheld';

    public function label(): string
    {
        return (string) __('candidate.grievance.decision.'.$this->value);
    }
}
