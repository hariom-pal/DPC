<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Enums;

/**
 * The status the engine reaches for one candidate (requirements §6.3; change
 * register of 2026-08-27, item 24).
 *
 * > *Candidate Status shall be — Eligible, Not Eligible, CIR, Rejected.*
 *
 * Four values, and the distinction between the last three is what the register
 * spends items 12 to 20 establishing:
 *
 * | | Means | Reached when |
 * |---|---|---|
 * | `EL` | Promotable | Every gate passed and the qualifying score met |
 * | `NE` | Does not qualify **yet** | The recruitment rule is not satisfied, or the merit score falls short |
 * | `CIR` | Held, and in circulation | A mandatory CR or Loyalty record is missing but justified with a reason and a PDF (item 19) |
 * | `RJ` | Out | Property Return missing, or the promotion is not lower-to-higher (items 11, 13) |
 *
 * **`CIR` is not a failure.** Item 19 is explicit — *"Candidate shall continue in
 * the DPC"* — so a CIR candidate is scored, ranked and carried forward; the
 * promotion is *held* pending the missing record rather than refused. That is why it
 * is a status of its own and not a flavour of Not Eligible.
 *
 * **`Rejected` and `NotEligible` are deliberately different**, and which applies to
 * a recruitment-rule failure is the one place the register contradicts itself: item
 * 12 says *Not Eligible*, item 20 lists the same condition under *Automatically
 * Reject*. Item 12 is applied, because it states the status directly for those three
 * conditions while item 20 is a list of the automatic gates. Recorded as **BD-32**
 * and switchable by `dpc.merit.rule_failure_status` — so a ruling is a setting.
 *
 * `SS` is not a status. Super Seat is an *outcome* on top of eligibility, and it is
 * carried separately — see `SuperSeatVerdict`.
 *
 * `CC` / `PUNISHMENT` are not here either: those are Note values the officer types
 * on the S18 chart, not verdicts the engine reaches.
 */
enum RemarkCode: string
{
    case Eligible = 'EL';
    case NotEligible = 'NE';

    /**
     * Super Seat.
     *
     * Predates the register of 2026-08-27 and is kept exactly as item 23 requires
     * ("implement the existing Super Seat logic exactly as defined in the PPT").
     * It rides alongside eligibility rather than replacing it.
     */
    case SuperSeat = 'SS';

    /**
     * Circulation — the promotion is held, and the candidate continues (item 19).
     */
    case Circulation = 'CIR';

    /**
     * Rejected outright (items 11, 13 and 20).
     */
    case Rejected = 'RJ';

    /**
     * Sealed Cover — a departmental enquiry with a charge-sheet, or a police
     * charge-sheet before a court, is pending (Coder Instructions rule 2.2). The
     * officer is assessed, the finding is kept in a sealed cover, and the seat the
     * officer would take is held. Not `SC`, which is a reservation category code.
     */
    case SealedCover = 'SCOVER';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Eligible',
            self::NotEligible => 'Not Eligible',
            self::SuperSeat => 'Super Seat',
            self::Circulation => 'CIR',
            self::Rejected => 'Rejected',
            self::SealedCover => 'Sealed Cover',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Eligible => 'text-bg-success',
            self::NotEligible => 'text-bg-secondary',
            self::SuperSeat => 'text-bg-primary',
            self::Circulation => 'text-bg-warning',
            self::Rejected => 'text-bg-danger',
            self::SealedCover => 'text-bg-dark',
        };
    }

    /**
     * Whether a candidate with this status stays in the exercise.
     *
     * `CIR` does (item 19 says so in as many words); `Rejected` and `NotEligible` do
     * not. Asked wherever a list is filtered, so the rule lives here rather than as
     * a repeated comparison one caller eventually gets backwards.
     */
    public function continuesInDpc(): bool
    {
        return $this === self::Eligible
            || $this === self::SuperSeat
            || $this === self::Circulation
            || $this === self::SealedCover;
    }

    /**
     * Whether this status is a refusal the officer can do nothing about within this
     * DPC — as opposed to a shortfall that a record or a later year could change.
     */
    public function isTerminal(): bool
    {
        return $this === self::Rejected;
    }
}
