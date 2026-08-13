<?php

declare(strict_types=1);

namespace App\Domain\Eligibility;

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
    ) {}

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
        );
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
        return [
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
    }
}
