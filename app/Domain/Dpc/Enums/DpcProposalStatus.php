<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

enum DpcProposalStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    public function isTerminal(): bool
    {
        return $this === self::Approved || $this === self::Cancelled;
    }

    /**
     * Why the department's five sections are read-only in this state, or null
     * while they are still editable.
     *
     * **Defined here rather than in the views** for the same reason
     * `VacancyCalculationService::seniorityCountMessage()` is defined once: an
     * officer who meets the same lock on the Seniority List, the Committee and
     * the Evaluation Chart has to be told the same thing, and the sentence has
     * to name the way *out* of the lock — otherwise a correct refusal is
     * indistinguishable from a broken screen.
     *
     * Raised from UAT (2026-08-23): the section screens simply dropped their
     * Manage button once a proposal was submitted, and the upload was reported
     * as "locked and broken" when it was locked and working.
     */
    public function lockReason(): ?string
    {
        return match ($this) {
            self::Draft, self::Returned => null,
            self::Submitted => 'This proposal has been submitted for approval, so its sections are now read-only. '
                .'The Administrative Head must return it for correction before the Seniority List or any other '
                .'section can be uploaded or changed again.',
            self::Approved => 'This proposal has been approved, digitally signed, and forwarded to CGPSC, so its '
                .'sections are permanently read-only. A correction at this stage is a Review DPC, not an edit.',
            self::Cancelled => 'This proposal was cancelled, so its sections are read-only. Start a new proposal to '
                .'upload a fresh Seniority List.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'text-bg-secondary',
            self::Submitted => 'text-bg-info',
            self::Returned => 'text-bg-warning',
            self::Approved => 'text-bg-success',
            self::Cancelled => 'text-bg-dark',
        };
    }
}
