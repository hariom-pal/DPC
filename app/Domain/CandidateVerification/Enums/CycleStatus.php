<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Enums;

enum CycleStatus: string
{
    /** Candidates may verify and submit until the deadline. */
    case Open = 'open';

    /** Closed with grievances: with the AH until each is resolved. */
    case GrievanceReview = 'grievance_review';

    /** Closed and sent to the Secretary (no grievance, or every one resolved). */
    case Completed = 'completed';
}
