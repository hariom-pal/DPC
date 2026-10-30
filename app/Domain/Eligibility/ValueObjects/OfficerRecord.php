<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\ValueObjects;

use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Master\Data\QualificationCatalogue;
use App\Domain\Master\Models\RecruitmentRuleSource;
use Illuminate\Support\Str;

/**
 * The engine's input: one officer's raw, already-recorded data — M5 Phase 1,
 * extended by the change register of 2026-08-27 for the merit gates.
 *
 * A plain value object rather than an Eloquent model, for two reasons. It lets every
 * rule be unit-tested and golden-tested without a database, and it keeps the engine
 * reading from a snapshot: M4 stores proposal-time snapshots precisely so a
 * historical calculation stays reproducible, and an engine holding live models could
 * quietly recompute against changed data.
 *
 * `fromEvaluationRow()` is the only bridge to M4, which stays frozen and unaware of
 * this milestone.
 *
 * **What the register of 2026-08-27 added**, and why each is here rather than looked
 * up inside a gate:
 *
 * | Field | Read by | Item |
 * |---|---|---|
 * | `propertyReturnYears` | the rejection gate | 13 |
 * | `loyaltyByYear` | the CIR gate | 15 |
 * | `justifiedCrGapYears`, `justifiedLoyaltyGapYears` | the CIR gate | 14, 15, 19 |
 * | `tribalAreaByYear` | reporting; **not** the formula | 18 |
 * | `qualification`, `requiredQualifications` | the recruitment-rule gate | 12 |
 * | `serviceMonthsOnFeederPost`, `requiredExperienceMonths`, `requiredPromotionDurationMonths` | the recruitment-rule gate | 12 |
 *
 * **`tribalAreaByYear` is carried but not scored**, and that is the register's own
 * tension rather than an oversight: item 18 wants the benefit attributed to its CR
 * year, item 21 forbids modifying the marking formula, and `ScoringService` still
 * applies PPT §5.2's single flat bonus from `tribalAreaServiceYears`. Recorded as
 * **BD-36**.
 */
final class OfficerRecord
{
    /**
     * @param  array<int, string|null>  $crGrades  year => grade (null = year on the chart, no grade recorded)
     * @param  array<int, bool|null>  $propertyReturnYears  year => filed
     * @param  array<int, bool|null>  $loyaltyByYear  year => certificate held
     * @param  array<int, bool|null>  $tribalAreaByYear  year => served in a tribal area (item 18)
     * @param  array<int, string>  $justifiedCrGapYears  year => citation
     * @param  array<int, string>  $justifiedLoyaltyGapYears  year => citation
     * @param  list<string>  $requiredQualifications  what the recruitment rule accepts
     */
    public function __construct(
        public readonly string $name,
        public readonly array $crGrades,
        public readonly ?int $tribalAreaServiceYears = null,
        public readonly bool $isReservedCategory = false,
        public readonly ?string $reservationCategoryCode = null,
        public readonly ?int $serialNumber = null,
        public readonly array $propertyReturnYears = [],
        public readonly array $loyaltyByYear = [],
        public readonly array $tribalAreaByYear = [],
        public readonly array $justifiedCrGapYears = [],
        public readonly array $justifiedLoyaltyGapYears = [],
        public readonly ?string $qualification = null,
        public readonly array $requiredQualifications = [],
        public readonly ?int $serviceMonthsOnFeederPost = null,
        public readonly ?int $requiredExperienceMonths = null,
        public readonly ?int $requiredPromotionDurationMonths = null,
        /** @var array<int, int> Each CR year's points on the Eligible CR Grades (Class Master rule runs). */
        public readonly array $crPoints = [],
        /** Why the finding is kept in a sealed cover (rule 2.2), or null. */
        public readonly ?string $sealedCover = null,
        /** Rule 2.3: on deputation without a sanctioned setup. */
        public readonly bool $unsanctionedDeputation = false,
        /** Rule 4.4: refused promotion to this post in the year before the DPC year. */
        public readonly bool $refusedPromotionLastYear = false,
        /** Rule 2.1: a PH claim verified by the Administrative Head — competes for a PH seat. */
        public readonly bool $phSeat = false,
    ) {}

    /**
     * Build from a saved evaluation row.
     *
     * `$source` is the recruitment rule's promotion source for this DPC's feeder
     * post, which is where the qualification, the qualifying service and the
     * promotion duration the officer is judged against come from (item 12). Null
     * when the proposal records no rule — in which case those gates have nothing to
     * check and are skipped, rather than failing everybody.
     */
    /**
     * @param  array<string, int>|null  $gradePoints  grade code => points; given, each CR year also
     *                                                carries its points (several CRs averaged, rule 3.1)
     */
    public static function fromEvaluationRow(DpcEvaluationRow $row, ?RecruitmentRuleSource $source = null, ?array $gradePoints = null): self
    {
        $row->loadMissing(
            'crGrades', 'reservationCategory', 'loyaltyFlags', 'propertyFlags',
            'recordGaps.reason', 'sealedCoverReason',
        );

        $grades = [];
        $points = [];
        $tribalByYear = [];

        foreach ($row->crGrades as $crGrade) {
            $grades[$crGrade->year] = $crGrade->grade;

            $yearPoints = $gradePoints === null ? null : $crGrade->pointsOn($gradePoints);

            if ($yearPoints !== null) {
                $points[$crGrade->year] = $yearPoints;
            }

            if ($crGrade->tribal_area !== null) {
                $tribalByYear[$crGrade->year] = (bool) $crGrade->tribal_area;
            }
        }

        $property = [];
        foreach ($row->propertyFlags as $flag) {
            $property[$flag->year] = $flag->flag;
        }

        $loyalty = [];
        foreach ($row->loyaltyFlags as $flag) {
            $loyalty[$flag->year] = $flag->flag;
        }

        /*
         | Only a gap with its supporting document attached counts as justified.
         |
         | Item 19 requires both the reason **and** the PDF, and makes them both
         | mandatory — so a row carrying a reason with no file has not met the
         | condition that opens the CIR path. Treating it as justified would let the
         | document requirement be skipped by saving the form and never uploading.
         */
        $crGaps = [];
        $loyaltyGaps = [];

        foreach ($row->recordGaps as $gap) {
            if (! $gap->isJustified()) {
                continue;
            }

            if ($gap->kind === DpcEvaluationRecordGap::KIND_CR) {
                $crGaps[$gap->year] = $gap->citation();
            } elseif ($gap->kind === DpcEvaluationRecordGap::KIND_LOYALTY) {
                $loyaltyGaps[$gap->year] = $gap->citation();
            }
        }

        return new self(
            name: $row->name,
            crGrades: $grades,
            tribalAreaServiceYears: $row->tribal_area_service_years,
            isReservedCategory: (bool) $row->reservationCategory?->is_reserved,
            reservationCategoryCode: $row->reservationCategory?->code,
            serialNumber: $row->sn,
            propertyReturnYears: $property,
            loyaltyByYear: $loyalty,
            tribalAreaByYear: $tribalByYear,
            justifiedCrGapYears: $crGaps,
            justifiedLoyaltyGapYears: $loyaltyGaps,
            qualification: $row->qualification,
            // "NA" names no qualification, so it requires none (2026-10-30).
            requiredQualifications: $source === null
                ? []
                : $source->qualifications->where('code', '!=', QualificationCatalogue::NOT_APPLICABLE)->pluck('name_en')->values()->all(),
            serviceMonthsOnFeederPost: self::monthsOnFeederPost($row),

            /*
             | Both requirements arrive in months, and they come from two places.
             |
             | **Experience** is the recruitment rule's, and the rule publishes it in
             | years *or* months — `min_experience_unit` exists because rounding 18
             | months up to a year changes who is eligible. So it is converted once,
             | on the master, rather than compared against a number whose unit the
             | gate would have to guess.
             |
             | **Promotion Duration** is the *proposal's*. It is not a column on
             | `recruitment_rule_sources` at all — it sits on `dpc_proposals`, carried
             | across from the Pre-DPC that sanctioned the exercise. Reading it off
             | the source (as this did until PHPStan caught it) returned null for
             | every officer, which silently switched item 12's third check off.
             */
            requiredExperienceMonths: $source?->minExperienceInMonths(),
            requiredPromotionDurationMonths: self::promotionDurationMonths($row),
            crPoints: $points,
            sealedCover: $row->isUnderSealedCover()
                ? trim($row->sealedCoverReason?->name_en.($row->vigilance_date ? ' ('.$row->vigilance_date->format('d-m-Y').')' : ''))
                : null,
            unsanctionedDeputation: $row->unsanctioned_deputation === true,
            refusedPromotionLastYear: $row->refusedPromotionLastYear(),
            phSeat: $row->holdsVerifiedPhClaim(),
        );
    }

    /**
     * §5.2 — the bonus turns on having served in a tribal area at all, not on how
     * long.
     *
     * Reads the officer-level total, which is what PPT §5.2 and `ScoringService`
     * use. `tribalAreaByYear` deliberately does not feed this — see the class
     * docblock and BD-36.
     */
    public function hasTribalAreaService(): bool
    {
        return ($this->tribalAreaServiceYears ?? 0) > 0;
    }

    /**
     * The most recent year this officer's chart mentions at all.
     *
     * Used to anchor the property-return window when nothing has been filed, so the
     * message names real years instead of a count. Considers every year the chart
     * carries — CR, property or loyalty — because a chart with no property rows at
     * all still has years in it.
     */
    public function mostRecentRecordYear(): ?int
    {
        $years = [
            ...array_keys($this->crGrades),
            ...array_keys($this->propertyReturnYears),
            ...array_keys($this->loyaltyByYear),
        ];

        return $years === [] ? null : max($years);
    }

    /**
     * Whether the officer's recorded qualification satisfies any the rule accepts
     * (item 12 read with item 11 of the register of 2026-08-26).
     *
     * **Any, not all.** A Schedule-IV entry listing several degrees lists
     * alternatives — "BE or BTech or MCA" — so meeting one is meeting the rule.
     * Joining them with "and" would refuse every real candidate.
     *
     * Matched on a normalised substring rather than equality, because the chart's
     * qualification is free text an operator typed: `M.A., B.Ed.` has to satisfy a
     * requirement recorded as `B.Ed.`, and it does not do so by being equal to it.
     */
    public function meetsAnyRequiredQualification(): bool
    {
        if ($this->requiredQualifications === []) {
            return true;
        }

        $held = self::normalise((string) $this->qualification);

        if ($held === '') {
            return false;
        }

        foreach ($this->requiredQualifications as $required) {
            $needle = self::normalise($required);

            if ($needle !== '' && str_contains($held, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Promotion Duration the proposal is being run under, in months.
     *
     * Recorded on the proposal in whole years, which is how the Schedule-IV entry
     * publishes it; converted here so the gate compares one unit throughout.
     */
    private static function promotionDurationMonths(DpcEvaluationRow $row): ?int
    {
        $years = $row->proposal?->promotion_duration_years;

        return $years === null || $years <= 0 ? null : $years * 12;
    }

    /**
     * Whole months between joining the feeder post and the chart being drawn up.
     *
     * **Months, not years.** A rule may require 18 months of qualifying service, and
     * an officer with 20 months of it satisfies that rule — measuring in whole years
     * would round them to 1 and refuse them. Since the requirement is carried in
     * months, the service has to be too.
     *
     * Null when the posting date is not recorded: the gate then has nothing to
     * compare and is skipped, rather than reading a missing date as zero service and
     * failing the officer for their department's data entry.
     */
    private static function monthsOnFeederPost(DpcEvaluationRow $row): ?int
    {
        $posted = $row->date_of_posting_on_promotion_from_post;

        if ($posted === null) {
            return null;
        }

        /*
         | Measured to the **proposal date**, not to today.
         |
         | A calculation re-run next year must give the answer it gave when the
         | Commission acted, and qualifying service measured against `now()` would
         | quietly grow. The proposal date is the moment the exercise was raised,
         | which is what the department's own chart is drawn up as at.
         */
        $asAt = $row->proposal?->proposal_date;

        return $asAt === null ? null : max(0, (int) $posted->diffInMonths($asAt));
    }

    /**
     * Case-folded, punctuation-stripped, for comparing free text against a master
     * name.
     */
    private static function normalise(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower($value));
    }
}
