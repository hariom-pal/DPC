<?php

declare(strict_types=1);

namespace App\Domain\Eligibility;

use App\Domain\Master\Models\PromotionCrRule;
use App\Enums\ServiceClass;
use InvalidArgumentException;

/**
 * An immutable snapshot of the scoring, eligibility, Super Seat and zone rules
 * in force at the moment a calculation runs (PPT C4–C6, requirements §5) —
 * M5 Phases 1–2.
 *
 * Nothing in the engine reads `config()` directly. Everything reads a RuleSet,
 * which is captured once per calculation, because a promotion computed under
 * one government notification has to stay explicable years later even after
 * the notification changes. `toArray()` is the configuration snapshot the
 * architect requires every calculation run to persist, and `version` is the
 * label it is explained by.
 *
 * **Older rule versions stay executable** (architect directive) through
 * `fromSnapshot()`: a stored run carries its own complete rule set, so
 * re-running a 2026 calculation in 2031 reconstructs the 2026 rules from the
 * run itself. No rule version is ever deleted, because none is ever stored
 * centrally in a form that could be — each run owns its copy. `toArray()` and
 * `fromSnapshot()` are exact inverses, which is asserted by a golden test.
 */
final class RuleSet
{
    /**
     * @param  array<string, int>  $crGradeMarks
     * @param  array<string, string>  $lastYearForbiddenGrades
     * @param  array<string, int>  $qualifyingScores
     * @param  array<string, array{single_vacancy_size: int, multiplier: int, addend: int}>  $zoneRules
     * @param  array{rule_id: int, pair: string, effective_from_year: int, dpc_year: int, qualifying_total: int, super_seat_points: int|null, minimum_by_year: array<int, int>, mandatory_years: list<int>, tribal_bonus_basis: string, tribal_bonus_skips_extremes: bool, highest_points: int|null, lowest_points: int|null}|null  $classRule  the Class Master promotion rule the run applies; null: the configured rules only
     */
    public function __construct(
        public readonly string $version,
        public readonly array $crGradeMarks,
        public readonly int $scoringYears,
        public readonly int $tribalAreaBonus,
        public readonly string $disqualifyingGrade,
        public readonly array $lastYearForbiddenGrades,
        public readonly array $qualifyingScores,
        public readonly bool $reservedRelaxationEnabled,
        public readonly int $reservedRelaxationFloor,
        public readonly string $superSeatGrade,
        public readonly array $zoneRules,
        public readonly bool $reservedZoneExpansionEnabled,
        public readonly int $reservedZoneExpansionMultiplier,
        public readonly ?array $classRule = null,
    ) {}

    /**
     * The rules a DPC is calculated under since 2026-10-21: the Class Master
     * promotion rule for its class pair and year, on the Eligible CR Grades' points.
     *
     * - marks: the points of each grade code valid on 1 January of the DPC year;
     * - window: the rule's CR years (the most recent graded years, as before);
     * - qualifying: the total of the window's points plus the rule's tribal bonus
     *   must reach the rule's qualifying total — the bonus for each window year served
     *   in a tribal area, and, if the rule says so, not for a year at the highest or
     *   the lowest grade (2026-10-21);
     * - a year below the rule's minimum for it is not eligible;
     * - Super Seat: every window year at or above the rule's super-seat points;
     * - each CR year the rule makes mandatory is recorded or justified (the gates).
     *
     * The grade gates, the relaxation and the zone stay as configured.
     *
     * @param  array<string, int>  $points  grade code => points
     */
    public static function forPromotionRule(self $base, PromotionCrRule $rule, array $points, int $dpcYear, string $pairKey): self
    {
        $rule->loadMissing('years');
        $minimums = [];
        $mandatory = [];

        foreach ($rule->years as $year) {
            if ($year->years_before > $rule->cr_years) {
                continue;
            }

            if ($year->cr_min_marks !== null) {
                $minimums[$dpcYear - $year->years_before] = (int) $year->cr_min_marks;
            }

            if ($year->cr_mandatory) {
                $mandatory[] = $dpcYear - $year->years_before;
            }
        }

        $total = (int) ($rule->qualifying_total ?? round((float) $rule->qualifying_average * $rule->cr_years));
        $superSeat = $rule->super_seat_grade === null ? null : (int) $rule->super_seat_grade;

        return new self(
            version: $base->version,
            crGradeMarks: $points,
            scoringYears: (int) $rule->cr_years,
            tribalAreaBonus: (int) $rule->tribal_bonus,
            disqualifyingGrade: $base->disqualifyingGrade,
            lastYearForbiddenGrades: $base->lastYearForbiddenGrades,
            qualifyingScores: [$pairKey => $total, 'default' => $total],
            reservedRelaxationEnabled: $base->reservedRelaxationEnabled,
            reservedRelaxationFloor: $base->reservedRelaxationFloor,
            superSeatGrade: $superSeat === null ? '' : (string) (array_search($superSeat, $points, true) ?: $superSeat.' points'),
            zoneRules: $base->zoneRules,
            reservedZoneExpansionEnabled: $base->reservedZoneExpansionEnabled,
            reservedZoneExpansionMultiplier: $base->reservedZoneExpansionMultiplier,
            classRule: [
                'rule_id' => (int) $rule->id,
                'pair' => $pairKey,
                'effective_from_year' => (int) $rule->effective_from_year,
                'dpc_year' => $dpcYear,
                'qualifying_total' => $total,
                'super_seat_points' => $superSeat,
                'minimum_by_year' => $minimums,
                'mandatory_years' => $mandatory,
                'tribal_bonus_basis' => $rule->tribal_bonus_basis === 'officer' ? 'officer' : 'year',
                'tribal_bonus_skips_extremes' => (bool) $rule->tribal_bonus_skips_extremes,
                'highest_points' => $points === [] ? null : max($points),
                'lowest_points' => $points === [] ? null : min($points),
            ],
        );
    }

    /** The minimum points the Class Master rule sets for a calendar year, if any. */
    public function minimumFor(int $year): ?int
    {
        return $this->classRule['minimum_by_year'][$year] ?? null;
    }

    /**
     * The CR years the Class Master rule makes mandatory.
     *
     * @return list<int>
     */
    public function mandatoryCrYears(): array
    {
        return $this->classRule['mandatory_years'] ?? [];
    }

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $scoring */
        $scoring = config('dpc.scoring');

        return self::build($scoring);
    }

    /**
     * Reconstruct the exact rule set a stored calculation ran under
     * (architect directive: older RuleSets must always remain executable).
     *
     * @param  array<string, mixed>  $snapshot  the value produced by toArray()
     */
    public static function fromSnapshot(array $snapshot): self
    {
        foreach (['rule_version', 'cr_grade_marks', 'scoring_years', 'qualifying_scores'] as $required) {
            if (! array_key_exists($required, $snapshot)) {
                throw new InvalidArgumentException("Rule snapshot is missing [{$required}] and cannot be reconstructed.");
            }
        }

        return self::build($snapshot);
    }

    /**
     * Both entry points share one shape, so a snapshot and live configuration
     * cannot drift apart in how they are read.
     *
     * @param  array<string, mixed>  $source
     */
    private static function build(array $source): self
    {
        return new self(
            version: (string) ($source['rule_version'] ?? $source['version']),
            crGradeMarks: $source['cr_grade_marks'],
            scoringYears: (int) $source['scoring_years'],
            tribalAreaBonus: (int) $source['tribal_area_bonus'],
            disqualifyingGrade: (string) ($source['gates']['disqualifying_grade'] ?? $source['disqualifying_grade']),
            lastYearForbiddenGrades: $source['gates']['last_year_forbidden_grade'] ?? $source['last_year_forbidden_grade'],
            qualifyingScores: $source['qualifying_scores'],
            reservedRelaxationEnabled: (bool) $source['reserved_relaxation']['enabled'],
            reservedRelaxationFloor: (int) $source['reserved_relaxation']['floor'],
            superSeatGrade: (string) $source['super_seat']['required_grade'],
            zoneRules: $source['zone']['rules'],
            reservedZoneExpansionEnabled: (bool) $source['zone']['reserved_expansion']['enabled'],
            reservedZoneExpansionMultiplier: (int) $source['zone']['reserved_expansion']['multiplier'],
            classRule: isset($source['class_rule']) && is_array($source['class_rule']) ? self::classRuleFrom($source['class_rule']) : null,
        );
    }

    /**
     * JSON turns integer keys into strings; the snapshot is read back to the shape
     * it was written in.
     *
     * @param  array<string, mixed>  $rule
     * @return array{rule_id: int, pair: string, effective_from_year: int, dpc_year: int, qualifying_total: int, super_seat_points: int|null, minimum_by_year: array<int, int>, mandatory_years: list<int>, tribal_bonus_basis: string, tribal_bonus_skips_extremes: bool, highest_points: int|null, lowest_points: int|null}
     */
    private static function classRuleFrom(array $rule): array
    {
        $minimums = [];

        foreach ((array) ($rule['minimum_by_year'] ?? []) as $year => $min) {
            $minimums[(int) $year] = (int) $min;
        }

        return [
            'rule_id' => (int) $rule['rule_id'],
            'pair' => (string) $rule['pair'],
            'effective_from_year' => (int) $rule['effective_from_year'],
            'dpc_year' => (int) $rule['dpc_year'],
            'qualifying_total' => (int) $rule['qualifying_total'],
            'super_seat_points' => $rule['super_seat_points'] === null ? null : (int) $rule['super_seat_points'],
            'minimum_by_year' => $minimums,
            'mandatory_years' => array_values(array_map('intval', (array) ($rule['mandatory_years'] ?? []))),
            // A run recorded before the tribal terms existed gave the single bonus.
            'tribal_bonus_basis' => ($rule['tribal_bonus_basis'] ?? 'officer') === 'year' ? 'year' : 'officer',
            'tribal_bonus_skips_extremes' => (bool) ($rule['tribal_bonus_skips_extremes'] ?? false),
            'highest_points' => isset($rule['highest_points']) ? (int) $rule['highest_points'] : null,
            'lowest_points' => isset($rule['lowest_points']) ? (int) $rule['lowest_points'] : null,
        ];
    }

    /**
     * Marks for a CR grade, or null when the grade is unknown or absent.
     *
     * Null is not zero. A missing CR means "no assessment on record", which is
     * what a Circulation DPC exists to handle; scoring it as zero would
     * silently convert an administrative gap into a failed officer.
     */
    public function markFor(?string $grade): ?int
    {
        if ($grade === null || $grade === '') {
            return null;
        }

        return $this->crGradeMarks[$grade] ?? null;
    }

    public function knowsGrade(string $grade): bool
    {
        return array_key_exists($grade, $this->crGradeMarks);
    }

    /**
     * §5.4 — the minimum score for this promotion, falling back to the PPT's
     * "शेष" (all other cases) entry.
     */
    public function qualifyingScoreFor(ServiceClass $from, ServiceClass $to): int
    {
        return (int) ($this->qualifyingScores[$this->pairKey($from, $to)]
            ?? $this->qualifyingScores['default']);
    }

    /**
     * §5.3 — the grade the most recent CR may not be, for this class pair.
     * Null when no such gate applies to the pair.
     */
    public function lastYearForbiddenGradeFor(ServiceClass $from, ServiceClass $to): ?string
    {
        return $this->lastYearForbiddenGrades[$this->pairKey($from, $to)] ?? null;
    }

    /**
     * §5.6 — the zone-sizing formula for this class pair, or null when the
     * pair has no numeric zone (the PPT's "other cases").
     *
     * @return array{single_vacancy_size: int, multiplier: int, addend: int}|null
     */
    public function zoneRuleFor(ServiceClass $from, ServiceClass $to): ?array
    {
        return $this->zoneRules[$this->pairKey($from, $to)] ?? null;
    }

    public function pairKey(ServiceClass $from, ServiceClass $to): string
    {
        return $from->value.'-'.$to->value;
    }

    /**
     * The configuration snapshot persisted with every calculation run
     * (architect directive), and the exact input `fromSnapshot()` expects.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $snapshot = [
            'rule_version' => $this->version,
            'cr_grade_marks' => $this->crGradeMarks,
            'scoring_years' => $this->scoringYears,
            'tribal_area_bonus' => $this->tribalAreaBonus,
            'gates' => [
                'disqualifying_grade' => $this->disqualifyingGrade,
                'last_year_forbidden_grade' => $this->lastYearForbiddenGrades,
            ],
            'qualifying_scores' => $this->qualifyingScores,
            'reserved_relaxation' => [
                'enabled' => $this->reservedRelaxationEnabled,
                'floor' => $this->reservedRelaxationFloor,
            ],
            'super_seat' => [
                'required_grade' => $this->superSeatGrade,
            ],
            'zone' => [
                'rules' => $this->zoneRules,
                'reserved_expansion' => [
                    'enabled' => $this->reservedZoneExpansionEnabled,
                    'multiplier' => $this->reservedZoneExpansionMultiplier,
                ],
            ],
        ];

        // Present only on a run under a Class Master rule, so a snapshot of the
        // configured rules reads exactly as it always did.
        if ($this->classRule !== null) {
            $snapshot['class_rule'] = $this->classRule;
        }

        return $snapshot;
    }
}
