<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * Draft → Finalized, and Superseded once a later revision of the same position is
 * finalized. A finalized or superseded position is never edited.
 *
 * A section-wise revision (2026-11-13) goes Draft → Pending Approval (the AO
 * submitted it to the AH) → Finalized on approval, or back to Draft when returned.
 */
enum VacancyPositionStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Finalized = 'finalized';
    case Superseded = 'superseded';

    public function label(): string
    {
        return __("vacancymaster.status.{$this->value}");
    }
}
