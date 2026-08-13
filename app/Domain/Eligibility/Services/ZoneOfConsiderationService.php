<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Services;

use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\ValueObjects\ZoneOfConsideration;
use App\Enums\ServiceClass;

/**
 * How many officers are considered for promotion (PPT S17/S18, requirements
 * §5.6) — M5 Phase 2.
 *
 * Pure, like the Phase 1 engines: no database, no clock, no configuration read
 * inside. Every multiplier and addend arrives on the RuleSet.
 *
 * The Class-I to Class-I formula reproduces the PPT's own worked example
 * ("पदोन्नति हेतु कुल विचार्य संख्या – 2 X 2 + 4 = 8"). Class pairs with no
 * configured rule follow the PPT's "other cases": every officer meeting the
 * experience qualification is considered, with no arithmetic cap — expressed
 * here as an uncapped zone rather than as a very large number, because the two
 * are different facts and a stored calculation should not blur them.
 */
class ZoneOfConsiderationService
{
    /**
     * @param  int  $availableOfficers  officers on the seniority list who could be considered
     * @param  array<string, int>  $vacanciesByCategory  reserved-category code => vacancy count
     * @param  array<string, int>  $eligibleByCategory  reserved-category code => officers clearing eligibility
     */
    public function determine(
        ServiceClass $fromClass,
        ServiceClass $toClass,
        int $vacancies,
        int $availableOfficers,
        RuleSet $rules,
        array $vacanciesByCategory = [],
        array $eligibleByCategory = [],
    ): ZoneOfConsideration {
        $rule = $rules->zoneRuleFor($fromClass, $toClass);
        $pairLabel = "Class-{$fromClass->value} to Class-{$toClass->value}";

        if ($vacancies < 1) {
            return new ZoneOfConsideration(
                size: 0,
                vacancies: $vacancies,
                availableOfficers: $availableOfficers,
                effectiveSize: 0,
                formula: 'no vacancy',
                explanation: 'No vacancy is recorded, so no officer falls within the zone of consideration.',
            );
        }

        if ($rule === null) {
            // PPT "other cases" — all officers meeting the experience
            // qualification. The filtering is the caller's; the zone imposes
            // no cap of its own.
            return new ZoneOfConsideration(
                size: null,
                vacancies: $vacancies,
                availableOfficers: $availableOfficers,
                effectiveSize: $availableOfficers,
                formula: 'all qualified officers',
                explanation: "For a {$pairLabel} promotion no zone formula applies; every officer meeting the "
                    .'experience qualification is considered ('.$availableOfficers.' available).',
            );
        }

        [$size, $formula] = $vacancies === 1
            ? [$rule['single_vacancy_size'], "1 vacancy → top {$rule['single_vacancy_size']}"]
            : [
                $rule['multiplier'] * $vacancies + $rule['addend'],
                "{$rule['multiplier']} × {$vacancies} + {$rule['addend']}",
            ];

        $effective = min($size, $availableOfficers);

        $explanation = "For a {$pairLabel} promotion with {$vacancies} vacancy/vacancies the zone is {$formula} = {$size}.";
        if ($availableOfficers < $size) {
            $explanation .= " Only {$availableOfficers} officer(s) are available, so {$effective} are considered.";
        }

        return new ZoneOfConsideration(
            size: $size,
            vacancies: $vacancies,
            availableOfficers: $availableOfficers,
            effectiveSize: $effective,
            formula: $formula,
            explanation: $explanation,
            pendingDecisions: $this->reservedShortfalls($rules, $vacanciesByCategory, $eligibleByCategory),
        );
    }

    /**
     * §5.6 — reserved-category shortfall expansion (7 x that category's
     * vacancy, for that category only).
     *
     * Part of decision Q5 and therefore WAITING FOR BUSINESS DECISION
     * (architect ruling: do not guess). Nothing is expanded. Categories whose
     * eligible officers fall short of their vacancies are *named*, so the
     * cases a ruling would resolve can be listed rather than silently decided
     * — the same treatment the qualifying-score relaxation gets.
     *
     * @param  array<string, int>  $vacanciesByCategory
     * @param  array<string, int>  $eligibleByCategory
     * @return list<string>
     */
    private function reservedShortfalls(RuleSet $rules, array $vacanciesByCategory, array $eligibleByCategory): array
    {
        if ($rules->reservedZoneExpansionEnabled || $vacanciesByCategory === []) {
            return [];
        }

        $pending = [];

        foreach ($vacanciesByCategory as $category => $categoryVacancies) {
            if ($categoryVacancies < 1) {
                continue;
            }

            $eligible = $eligibleByCategory[$category] ?? 0;
            if ($eligible >= $categoryVacancies) {
                continue;
            }

            $expanded = $rules->reservedZoneExpansionMultiplier * $categoryVacancies;
            $pending[] = "Category {$category}: {$eligible} eligible officer(s) against {$categoryVacancies} "
                ."vacancy/vacancies. The zone could be expanded to {$expanded} for this category, but that rule "
                .'is awaiting a business decision (Q5) and has not been applied.';
        }

        return $pending;
    }
}
