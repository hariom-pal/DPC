<?php

declare(strict_types=1);

namespace App\Domain\Eligibility;

/**
 * Which CR years an officer is judged on (business decisions of 2026-11-14 and
 * 2026-11-16).
 *
 * Counting back from the year before the DPC year, the window keeps going until it
 * has as many counted years as the rule asks for (five):
 *
 *  - a year whose CR was not put up, or was put up without a grading, is **skipped**
 *    when it is a middle year — it does not count, and one older year joins the
 *    window in its place;
 *  - the **last** year (the year before the DPC year) is never skipped: missing or
 *    not, it is one of the counted years, so a missing last year adds nothing;
 *  - no year before the officer's first year of service is taken.
 *
 * A skipped year is still one the officer is answerable for: missing with its
 * reason and document it holds the promotion (CIR), unexplained it refuses it
 * (2026-11-16: "Replaced, still CIR"). The rule's per-year requirements (minimum
 * points, mandatory) apply to the counted years by position: year 1 is the latest
 * counted year, year 2 the next, and so on (2026-11-16).
 *
 * One calculation for the CR entry screen, the rule check and the DPC calculation,
 * so the three never disagree on the years.
 */
final class CrWindow
{
    /**
     * @param  list<int>  $years  every year in the window, latest first
     * @param  list<int>  $counted  the counted years, latest first (position 1 first)
     * @param  list<int>  $skipped  middle years not counted, latest first
     */
    private function __construct(
        public readonly array $years,
        public readonly array $counted,
        public readonly array $skipped,
    ) {}

    /**
     * @param  int  $lastYear  the year before the DPC year
     * @param  int  $slots  how many counted years the rule asks for
     * @param  list<int>  $notCountedYears  years recorded as not put up, or put up without a grading
     * @param  int  $floorYear  the earliest year the window may reach
     */
    public static function of(int $lastYear, int $slots, array $notCountedYears, int $floorYear): self
    {
        $slots = max(1, $slots);
        $notCounted = array_flip($notCountedYears);
        $years = [];
        $counted = [];
        $skipped = [];

        for ($year = $lastYear; $year >= $floorYear && count($counted) < $slots; $year--) {
            $years[] = $year;

            if ($year !== $lastYear && isset($notCounted[$year])) {
                $skipped[] = $year;

                continue;
            }

            $counted[] = $year;
        }

        return new self($years, $counted, $skipped);
    }

    /** A counted year's position (1 = latest counted), or null. */
    public function positionOf(int $year): ?int
    {
        $index = array_search($year, $this->counted, true);

        return $index === false ? null : $index + 1;
    }
}
