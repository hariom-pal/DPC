<?php

declare(strict_types=1);

namespace App\Domain\SelfInspection\Enums;

use App\Domain\Dpc\Models\DpcEvaluationRow;
use Illuminate\Support\Carbon;

/**
 * A fact an officer proves their identity with on S50.
 *
 * Every case maps to a column of the **ten-field employee snapshot** — the same
 * ten fields `dpc_evaluation_rows`, `dpc_working_officers` and
 * `dpc_seniority_entries` all carry (architect directive, 2026-08-13). Nothing
 * here reads a live employee record, because none exists to read.
 *
 * **Which of these are required is configuration**, not a property of this enum:
 * see `dpc.self_inspection.authentication.factors`.
 */
enum SelfInspectionFactor: string
{
    case Name = 'name';
    case Dob = 'dob';
    case DateOfPostingOnPromotionToPost = 'date_of_posting_on_promotion_to_post';
    case DateOfPostingOnPromotionFromPost = 'date_of_posting_on_promotion_from_post';
    case DateOfJoiningService = 'date_of_joining_service';
    case EmployeeNumber = 'employee_number';

    /**
     * The label the officer sees, in the deck's own words where it has them.
     *
     * S50 labels its two fields *"NAME AS ON AADHAR CARD"* and *"DATE OF
     * JOINING PROMOTION TO POST"*. Aadhaar is gone (Q1), so the first becomes
     * simply the name on the department's record — which is what the officer
     * has to match either way.
     */
    public function label(): string
    {
        return match ($this) {
            self::Name => 'Name (as recorded by your department)',
            self::Dob => 'Date of Birth',
            self::DateOfPostingOnPromotionToPost => 'Date of Joining Promotion To Post',
            self::DateOfPostingOnPromotionFromPost => 'Date of Joining Promotion From Post',
            self::DateOfJoiningService => 'Date of Joining Service',
            self::EmployeeNumber => 'Employee Number',
        };
    }

    public function isDate(): bool
    {
        return match ($this) {
            self::Name, self::EmployeeNumber => false,
            default => true,
        };
    }

    /**
     * Laravel validation rules for this factor's input.
     *
     * @return list<string>
     */
    public function validationRules(): array
    {
        return $this->isDate()
            ? ['required', 'date']
            : ['required', 'string', 'max:255'];
    }

    /**
     * Whether the officer's answer matches what the department recorded.
     *
     * **Dates are compared as dates and names as normalised strings, and
     * neither is compared loosely.** A partial or fuzzy name match would turn
     * this screen into an enumeration tool: an attacker holding a seniority
     * list already has every name on it, and a prefix match would let one
     * guessed date open several officers' records instead of none.
     */
    public function matches(DpcEvaluationRow $row, string $answer): bool
    {
        if ($this->isDate()) {
            $stored = $row->{$this->value};

            if (! $stored instanceof Carbon) {
                // Every date but `dob` is nullable on the snapshot. A factor the
                // department never recorded cannot be proved, and must not pass
                // by being absent on both sides.
                return false;
            }

            try {
                return $stored->isSameDay(Carbon::parse($answer));
            } catch (\Throwable) {
                return false;
            }
        }

        $stored = $row->{$this->value};

        if ($stored === null || $stored === '') {
            return false;
        }

        return self::normalise((string) $stored) === self::normalise($answer);
    }

    /**
     * Case-folded, with internal whitespace collapsed — so "ANIL  KUMAR verma"
     * matches "Anil Kumar Verma". Nothing else is stripped: initials, dots and
     * honorifics are part of how a department records a name, and discarding
     * them would widen the match rather than normalise it.
     */
    public static function normalise(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /**
     * The configured factor set, in a stable order, with unknown names dropped.
     *
     * Falls back to the PPT's own two plus date of birth if configuration is
     * empty — an empty factor list would authenticate everybody, so it is the
     * one value here that cannot be honoured as written.
     *
     * @return list<self>
     */
    public static function configured(): array
    {
        /** @var list<string> $names */
        $names = (array) config('dpc.self_inspection.authentication.factors', []);

        $factors = [];

        foreach ($names as $name) {
            $factor = self::tryFrom((string) $name);

            if ($factor !== null && ! in_array($factor, $factors, true)) {
                $factors[] = $factor;
            }
        }

        return $factors === []
            ? [self::Name, self::DateOfPostingOnPromotionToPost, self::Dob]
            : $factors;
    }
}
