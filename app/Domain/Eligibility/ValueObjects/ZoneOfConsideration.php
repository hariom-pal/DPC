<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\ValueObjects;

/**
 * How many officers fall within the zone of consideration, and how that
 * number was reached (PPT S17/S18 "पदोन्नति हेतु कुल विचार्य संख्या",
 * requirements §5.6) — M5 Phase 2.
 *
 * `formula` and `explanation` exist so a stored calculation can be explained
 * from its own data with no inference (architect directive). "8 officers were
 * considered" is not defensible on its own; "2 vacancies x 2 + 4 = 8" is.
 */
final class ZoneOfConsideration
{
    /**
     * @param  int|null  $size  null = no numeric cap; every qualified officer is considered
     * @param  list<string>  $pendingDecisions
     */
    public function __construct(
        public readonly ?int $size,
        public readonly int $vacancies,
        public readonly int $availableOfficers,
        public readonly int $effectiveSize,
        public readonly string $formula,
        public readonly string $explanation,
        public readonly array $pendingDecisions = [],
    ) {}

    /**
     * The PPT's "other cases" rule — all officers meeting the experience
     * qualification, with no arithmetic cap on the count.
     */
    public function isUncapped(): bool
    {
        return $this->size === null;
    }

    /**
     * The zone is larger than the officers actually available, so the list is
     * short through lack of candidates rather than through the formula.
     */
    public function isTruncatedByAvailability(): bool
    {
        return $this->size !== null && $this->availableOfficers < $this->size;
    }

    /**
     * A reserved-category shortfall that the unruled 7x expansion (Q5) could
     * have addressed. Recorded, never acted on.
     */
    public function needsBusinessDecision(): bool
    {
        return $this->pendingDecisions !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'size' => $this->size,
            'vacancies' => $this->vacancies,
            'available_officers' => $this->availableOfficers,
            'effective_size' => $this->effectiveSize,
            'formula' => $this->formula,
            'explanation' => $this->explanation,
            'uncapped' => $this->isUncapped(),
            'truncated_by_availability' => $this->isTruncatedByAvailability(),
            'pending_decisions' => $this->pendingDecisions,
        ];
    }
}
