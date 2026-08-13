<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\ValueObjects;

use App\Domain\Eligibility\Enums\RemarkCode;

/**
 * Whether one officer clears the eligibility rules, and why — M5 Phase 1.
 *
 * Reasons are recorded for every failure, because "not eligible" with no
 * stated ground is indefensible if the promotion is challenged.
 */
final class EligibilityVerdict
{
    /**
     * @param  list<string>  $failureReasons
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly RemarkCode $remark,
        public readonly int $requiredScore,
        public readonly int $achievedScore,
        public readonly array $failureReasons = [],
        public readonly bool $reservedRelaxationPending = false,
        public readonly bool $incompleteCrWindow = false,
    ) {}

    /**
     * This officer failed only on the qualifying score, is a reserved-category
     * officer, and would have cleared the relaxed floor — so the unruled
     * reserved-relaxation rule (Q5/Q6) is the only thing standing between this
     * verdict and a different one.
     *
     * Flagged rather than decided, by architect ruling: the relaxation is
     * WAITING FOR BUSINESS DECISION and must not be guessed in either
     * direction. The verdict stands as NOT eligible on the printed rule — the
     * conservative side, since granting eligibility on an unruled rule would
     * promote someone the rules may not entitle to it.
     */
    public function needsBusinessDecision(): bool
    {
        return $this->reservedRelaxationPending;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'remark' => $this->remark->value,
            'required_score' => $this->requiredScore,
            'achieved_score' => $this->achievedScore,
            'failure_reasons' => $this->failureReasons,
            'reserved_relaxation_pending' => $this->reservedRelaxationPending,
            'incomplete_cr_window' => $this->incompleteCrWindow,
        ];
    }
}
