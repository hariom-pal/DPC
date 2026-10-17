<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * What the PH questionnaire (rule 2.1) records for one officer in one proposal.
 *
 * Derived from the three answers and never stored, so it cannot disagree with them.
 * It is the **claim** position only: a recorded claim is not an approval. Eligibility
 * verification (`ph_verification_status`) and the final reservation decision
 * (`ph_final_decision`) are separate facts on the row.
 */
enum PhReservationOutcome: string
{
    /** The questionnaire has not been answered as far as it must go. */
    case NotRecorded = 'not_recorded';

    /** Case 1 — not PH: no PH reservation benefit. */
    case NotPh = 'not_ph';

    /** Case 2 — PH, benefit already availed once in service: no fresh benefit. */
    case PreviouslyAvailed = 'previously_availed';

    /** Case 3 — PH, not availed, does not want it: no benefit; undertaking required. */
    case Declined = 'declined';

    /** Case 4 — PH, not availed, wants it: claim recorded; undertaking and verification required. */
    case Claimed = 'claimed';

    public static function from3(?bool $isPh, ?bool $previouslyAvailed, ?bool $claims): self
    {
        return match (true) {
            $isPh === null => self::NotRecorded,
            $isPh === false => self::NotPh,
            $previouslyAvailed === null => self::NotRecorded,
            $previouslyAvailed === true => self::PreviouslyAvailed,
            $claims === null => self::NotRecorded,
            $claims === false => self::Declined,
            default => self::Claimed,
        };
    }

    public function label(): string
    {
        return __("dpc.employee_details.ph.outcomes.{$this->value}");
    }

    /**
     * Rule 2.1 and the business decision: the undertaking is mandatory for every PH
     * officer who reaches question 3, whatever the answer.
     */
    public function requiresUndertaking(): bool
    {
        return $this === self::Declined || $this === self::Claimed;
    }

    public function isClaim(): bool
    {
        return $this === self::Claimed;
    }
}
