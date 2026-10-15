<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

enum VacancyEntryEligibility: string
{
    /** Counts, subject to reconciliation. */
    case Eligible = 'eligible';

    /** Not counted until the Administrator verifies it. Blocks finalization. */
    case PendingVerification = 'pending_verification';

    /** Recorded, never counted. */
    case NotEligible = 'not_eligible';

    /** An existing vacancy: explains the opening position, never adds to it. */
    case Informational = 'informational';

    public function label(): string
    {
        return __("vacancymaster.eligibility.{$this->value}");
    }
}
