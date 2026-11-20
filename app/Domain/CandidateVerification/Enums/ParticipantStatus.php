<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Enums;

enum ParticipantStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case VerifiedAgreed = 'verified_agreed';
    case GrievanceSubmitted = 'grievance_submitted';
    /** The deadline passed with nothing submitted — never read as agreement. */
    case NoResponse = 'no_response';

    public function label(): string
    {
        return (string) __('candidate.verification.status.'.$this->value);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::VerifiedAgreed, self::GrievanceSubmitted, self::NoResponse], true);
    }
}
