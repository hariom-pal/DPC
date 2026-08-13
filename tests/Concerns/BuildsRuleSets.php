<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Eligibility\RuleSet;

/**
 * Builds a RuleSet for engine tests, defaulting to the PPT's own values with
 * any single rule overridable.
 *
 * Unit tests construct rule sets explicitly rather than reading configuration,
 * so a test that pins one rule cannot be moved by an unrelated config change.
 * The golden cases do the opposite on purpose — they run against the shipped
 * configuration, because their job is to prove what the application actually
 * deploys with.
 */
trait BuildsRuleSets
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function ruleSet(array $overrides = []): RuleSet
    {
        return new RuleSet(
            version: $overrides['version'] ?? 'test-1.0',
            crGradeMarks: $overrides['marks'] ?? ['क+' => 4, 'क' => 3, 'ख' => 2, 'ग' => 1, 'घ' => 0],
            scoringYears: $overrides['scoringYears'] ?? 5,
            tribalAreaBonus: $overrides['tribalBonus'] ?? 1,
            disqualifyingGrade: $overrides['disqualifyingGrade'] ?? 'घ',
            lastYearForbiddenGrades: $overrides['lastYearForbidden'] ?? ['I-I' => 'ग'],
            qualifyingScores: $overrides['qualifyingScores'] ?? ['I-I' => 15, 'II-I' => 13, 'II-II' => 11, 'default' => 10],
            reservedRelaxationEnabled: $overrides['relaxationEnabled'] ?? false,
            reservedRelaxationFloor: $overrides['relaxationFloor'] ?? 10,
            superSeatGrade: $overrides['superSeatGrade'] ?? 'क+',
            zoneRules: $overrides['zoneRules'] ?? [
                'I-I' => ['single_vacancy_size' => 5, 'multiplier' => 2, 'addend' => 4],
            ],
            reservedZoneExpansionEnabled: $overrides['zoneExpansionEnabled'] ?? false,
            reservedZoneExpansionMultiplier: $overrides['zoneExpansionMultiplier'] ?? 7,
        );
    }
}
