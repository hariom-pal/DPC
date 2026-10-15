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

        /**
         * The justified missing records behind a CIR (item 19).
         *
         * Carried on the verdict because it is what the promotion list has to print
         * beside a held candidate: "CIR" alone does not say what is awaited.
         *
         * @var list<string>
         */
        public readonly array $gateCitations = [],
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
     * The same verdict, restated under a gate's decision (change register of
     * 2026-08-27, items 12 to 20).
     *
     * **A gate overrides the score, never the other way round.** This object answers
     * "did the marks reach the minimum"; a `CandidateGateVerdict` answers "is this
     * candidate in contention at all". A candidate rejected for a missing Property
     * Return is rejected whatever they scored, and a CIR candidate is held whatever
     * they scored — item 19 says they continue in the DPC.
     *
     * **The score survives the override**, and both sets of reasons are kept. A
     * reader looking at a rejected row is entitled to see how the marks fell, and a
     * candidate refused promotion is entitled to every ground — the gate's and the
     * formula's. Discarding the score here would make the two engines' findings
     * mutually exclusive when they are cumulative.
     *
     * `eligible` becomes true only for a status that keeps the candidate in
     * contention *and* had already met the score. CIR is not eligibility: the
     * promotion is held, and a held promotion must not fill a seat.
     */
    public function overriddenBy(CandidateGateVerdict $gate): self
    {
        if ($gate->status === null) {
            return $this;
        }

        return new self(
            eligible: false,
            remark: $gate->status,
            requiredScore: $this->requiredScore,
            achievedScore: $this->achievedScore,
            failureReasons: [...$gate->reasons, ...$this->failureReasons],
            reservedRelaxationPending: $this->reservedRelaxationPending,
            incompleteCrWindow: $this->incompleteCrWindow,
            gateCitations: $gate->gapCitations,
        );
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
            'gate_citations' => $this->gateCitations,
        ];
    }
}
