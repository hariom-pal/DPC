<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\CalculationStatus;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Master\Models\RecruitmentRuleSource;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CALCULATE (PPT S33, requirements §5.7) — M5 Phase 3.
 *
 * **This is the boundary.** It is the only class in the milestone that reads
 * the database, and it exists so the four engines never have to: it loads a
 * proposal, converts it into plain value objects, calls the pure services, and
 * writes the outcome. Scoring, eligibility, zone and Super Seat stay pure,
 * deterministic, stateless, database- and clock-independent (architect
 * Decision 1) precisely because this class absorbs all of that.
 *
 * **Nothing is ever overwritten.** Each execution creates a new run. The
 * Commission acts on a calculation, and if a promotion is contested the
 * question is what the engine produced at that moment under the rules in force
 * then — so a recalculation must stand beside its predecessor, not replace it.
 */
class DpcCalculationService
{
    public function __construct(
        private readonly ScoringService $scoring,
        private readonly EligibilityService $eligibility,
        private readonly PromotionHierarchyService $hierarchy,
        private readonly CandidateGateService $gates,
        private readonly ZoneOfConsiderationService $zoneService,
        private readonly SuperSeatService $superSeat,
        private readonly ActivityTimelineService $timeline,
    ) {}

    public function calculate(DpcProposal $proposal, ?User $actor = null): DpcCalculationRun
    {
        $proposal->loadMissing(
            'promotionFromPost.payScale', 'promotionToPost.payScale',
            'evaluationRows.crGrades', 'evaluationRows.reservationCategory',

            // What the merit gates of 2026-08-27 read (items 12 to 19).
            'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags',
            'evaluationRows.recordGaps.reason',

            'vacancies.reservationCategory',
        );

        if ($proposal->evaluationRows->isEmpty()) {
            throw new WorkflowException('This proposal has no evaluation chart entries, so there is nothing to calculate.');
        }

        $rules = RuleSet::fromConfig();
        $fromClass = $proposal->promotionFromPost->class_level;
        $toClass = $proposal->promotionToPost->class_level;

        /*
         | Item 11, once per proposal.
         |
         | The post pair is the same for every candidate, so the comparison is made
         | once — but it produces a *per candidate* rejection, because a status is
         | something a candidate has and the promotion list has to say so against
         | each name.
         */
        $hierarchyFailures = $this->hierarchy->verdict(
            $proposal->promotionFromPost,
            $proposal->promotionToPost,
        )['failures'];

        // The recruitment rule's terms for *this* DPC's feeder post, which is what
        // item 12 judges qualification, experience and promotion duration against.
        $source = $this->promotionSourceFor($proposal);

        $propertyYears = (int) config('dpc.merit.property_return_years', 5);
        $requiredCrs = (int) config('dpc.merit.required_cr_count', 5);
        /*
         | Whether an unmatched qualification refuses a candidate or is merely
         | reported — BD-39, and `advise` by default.
         |
         | The Recruitment Rule names a *level* from the Qualification Master
         | ("Post-Graduate"); the chart carries the *degree* an operator typed
         | ("M.A., B.Ed."). Nothing here can know those describe the same officer, so
         | until the Commission supplies the mapping the finding is surfaced on the
         | run's pending decisions instead of refusing a promotion.
         */
        $enforceQualification = (string) config('dpc.merit.qualification_gate', 'advise') === 'reject';

        $ruleFailureStatus = RemarkCode::tryFrom((string) config('dpc.merit.rule_failure_status', 'NE'))
            ?? RemarkCode::NotEligible;

        // ---- pure engines ------------------------------------------------
        $officers = $proposal->evaluationRows
            ->map(fn ($row): array => [
                'row' => $row,
                'record' => OfficerRecord::fromEvaluationRow($row, $source),
            ])
            ->values();

        $assessed = $officers->map(function (array $entry) use (
            $rules, $fromClass, $toClass, $hierarchyFailures, $propertyYears, $requiredCrs,
            $ruleFailureStatus, $enforceQualification
        ): array {
            $record = $entry['record'];

            /*
             | The gates run **before** the formula (items 12 to 20).
             |
             | The score is still computed for every candidate, including a gated
             | one, and that is deliberate: a CIR candidate continues in the DPC
             | (item 19) and has to be ranked, and a reader looking at a Not Eligible
             | row is entitled to see how the marks fell. What a gate decides is the
             | *status*, not whether the arithmetic happens.
             */
            $gate = $this->gates->assess(
                $record, $hierarchyFailures, $propertyYears, $requiredCrs, $ruleFailureStatus,
                $enforceQualification,
            );

            $score = $this->scoring->score($record, $rules);
            $verdict = $this->eligibility->assess($record, $score, $fromClass, $toClass, $rules);

            return [
                'row' => $entry['row'],
                'record' => $record,
                'score' => $score,

                /*
                 | A gate overrides the score's verdict, never the other way round.
                 |
                 | `EligibilityService` answers "did the marks reach the minimum";
                 | the gates answer "is this candidate in contention at all". A
                 | candidate rejected for a missing Property Return is rejected
                 | whatever they scored, and a CIR candidate is held whatever they
                 | scored — item 19 says they continue.
                 */
                'gate' => $gate,
                'verdict' => $gate->isDecided() ? $verdict->overriddenBy($gate) : $verdict,
                'superSeat' => $this->superSeat->assess($record, $score, $rules),
            ];
        });

        $vacanciesByCategory = $this->vacanciesByCategory($proposal);
        $totalVacancies = array_sum($vacanciesByCategory);

        $eligibleByCategory = [];
        foreach ($assessed as $entry) {
            if ($entry['verdict']->eligible) {
                $code = $entry['record']->reservationCategoryCode ?? 'UNKNOWN';
                $eligibleByCategory[$code] = ($eligibleByCategory[$code] ?? 0) + 1;
            }
        }

        $zone = $this->zoneService->determine(
            $fromClass,
            $toClass,
            $totalVacancies,
            $assessed->count(),
            $rules,
            $vacanciesByCategory,
            $eligibleByCategory,
        );

        // ---- ranking and lists -------------------------------------------
        $ranked = $this->rank($assessed->all(), $zone->effectiveSize);
        $placed = $this->placeOnLists($ranked, $vacanciesByCategory);

        $pending = $this->collectPendingDecisions($placed, $zone->pendingDecisions);

        // ---- persistence --------------------------------------------------
        return DB::transaction(function () use ($proposal, $rules, $zone, $placed, $pending, $actor, $assessed): DpcCalculationRun {
            $run = DpcCalculationRun::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'status' => CalculationStatus::Draft->value,
                'rule_version' => $rules->version,
                // TD-21, closed 2026-08-19. Directive 1 asked for the engine
                // version "for every calculation run"; it reached the timeline, the
                // proceeding and every document, but not the run row it describes.
                // A run is the permanent evidence of a promotion decision — every
                // other place the version was recorded is a consumer of the run and
                // can be absent while the run survives.
                'calculation_engine_version' => (string) config('dpc.scoring.engine_version'),
                'configuration_snapshot' => $rules->toArray(),
                'proposal_version' => $this->proposalFingerprint($proposal),
                'application_version' => (string) config('dpc.application.version'),
                'git_commit_hash' => (string) config('dpc.application.git_commit'),
                'build_timestamp' => $this->buildTimestamp(),
                'executed_by' => $actor?->id,
                'executed_at' => now(),
                'zone' => $zone->toArray(),
                'officers_considered' => $assessed->count(),
                'eligible_count' => collect($placed)->where('verdict.eligible', true)->count(),
                'main_list_count' => collect($placed)->where('list_type', ListType::Main)->count(),
                'waiting_list_count' => collect($placed)->where('list_type', ListType::Waiting)->count(),
                'has_pending_decisions' => $pending !== [],
                'pending_decisions' => $pending === [] ? null : $pending,
            ]);

            foreach ($placed as $entry) {
                $run->results()->create([
                    'dpc_evaluation_row_id' => $entry['row']->id,
                    'sn' => $entry['record']->serialNumber ?? 0,
                    'officer_name' => $entry['record']->name,
                    'employee_number' => $entry['row']->employee_number,
                    'reservation_category_code' => $entry['record']->reservationCategoryCode ?? 'UNKNOWN',
                    'score_breakdown' => $entry['score']->toArray(),
                    'verdict' => $entry['verdict']->toArray(),
                    'super_seat' => $entry['superSeat']->toArray(),
                    'is_eligible' => $entry['verdict']->eligible,
                    'is_super_seat' => $entry['superSeat']->qualifies,
                    'in_zone' => $entry['in_zone'],
                    'remark' => $entry['remark'],
                    'list_type' => $entry['list_type']->value,
                    'list_position' => $entry['list_position'],
                    'rank' => $entry['rank'],
                ]);
            }

            // Draft -> Completed is the only update a run ever accepts.
            $run->update(['status' => CalculationStatus::Completed->value]);

            $this->timeline->record(
                'dpc_proposal.calculation_completed',
                $proposal,
                "Eligibility calculation completed: {$run->main_list_count} on the main list, "
                    ."{$run->waiting_list_count} waiting, under rule version {$rules->version}",
                [
                    'calculation_uuid' => $run->uuid,
                    'rule_version' => $rules->version,
                    // TD-21, closed 2026-08-19. Directive 1 asked for the engine
                    // version "for every calculation run"; it reached the timeline, the
                    // proceeding and every document, but not the run row it describes.
                    // A run is the permanent evidence of a promotion decision — every
                    // other place the version was recorded is a consumer of the run and
                    // can be absent while the run survives.
                    'calculation_engine_version' => (string) config('dpc.scoring.engine_version'),
                    'has_pending_decisions' => $run->has_pending_decisions,
                ],
                $actor,
            );

            return $run->fresh(['results']) ?? $run;
        });
    }

    /**
     * The recruitment rule's terms for **this DPC's** Promotion From Post.
     *
     * Item 12 judges qualification, experience and promotion duration against the
     * rule, and a rule states them *per feeder*: five years as an Assistant Director
     * is a different condition from five years as a Research Officer. So the source
     * row is what matters, not the rule as a whole.
     *
     * Null when the proposal records no rule, or the rule names no source for this
     * feeder. The gates then have nothing to check and are skipped — which is the
     * right answer rather than failing every candidate for a master-data gap.
     */
    private function promotionSourceFor(DpcProposal $proposal): ?RecruitmentRuleSource
    {
        if ($proposal->recruitment_rule_id === null || $proposal->promotion_from_post_id === null) {
            return null;
        }

        return RecruitmentRuleSource::query()
            ->with('qualifications')
            ->where('recruitment_rule_id', $proposal->recruitment_rule_id)
            ->where('promotion_from_post_id', $proposal->promotion_from_post_id)
            ->first();
    }

    /**
     * Total vacancies per reservation-category code (S16's row-based
     * breakdown, summed across vacancy types).
     *
     * @return array<string, int>
     */
    private function vacanciesByCategory(DpcProposal $proposal): array
    {
        $totals = [];

        foreach ($proposal->vacancies as $vacancy) {
            $code = $vacancy->reservationCategory->code;
            $totals[$code] = ($totals[$code] ?? 0) + $vacancy->count;
        }

        return $totals;
    }

    /**
     * Order officers for selection.
     *
     * Zone membership is decided by seniority — the first N officers on the
     * chart, N being the zone size. Within the zone, §5.5's notional
     * (काल्पनिक) seniority puts Super Seat officers ahead of the field, and
     * among themselves their actual seniority stands.
     *
     * Officers outside the zone are still assessed and still get a result row;
     * they are simply not ranked for selection, so their absence from both
     * lists is recorded rather than silent.
     *
     * @param  list<array<string, mixed>>  $assessed
     * @return list<array<string, mixed>>
     */
    private function rank(array $assessed, int $zoneSize): array
    {
        $bySeniority = collect($assessed)
            ->sortBy(fn (array $e): int => $e['record']->serialNumber ?? PHP_INT_MAX)
            ->values();

        $entries = [];
        foreach ($bySeniority as $index => $entry) {
            $entry['in_zone'] = $index < $zoneSize;
            $entries[] = $entry;
        }

        $selectable = array_values(array_filter(
            $entries,
            static fn (array $e): bool => $e['in_zone'] && $e['verdict']->eligible,
        ));

        usort($selectable, static function (array $a, array $b): int {
            $superSeat = ($b['superSeat']->qualifies <=> $a['superSeat']->qualifies);
            if ($superSeat !== 0) {
                return $superSeat;
            }

            return ($a['record']->serialNumber ?? PHP_INT_MAX) <=> ($b['record']->serialNumber ?? PHP_INT_MAX);
        });

        $rankBySn = [];
        foreach ($selectable as $position => $entry) {
            $rankBySn[$entry['record']->serialNumber] = $position + 1;
        }

        foreach ($entries as $index => $entry) {
            $entries[$index]['rank'] = $rankBySn[$entry['record']->serialNumber] ?? null;
        }

        return $entries;
    }

    /**
     * §5.7 — Main List and Waiting List, per reservation category.
     *
     * A category's vacancies are filled from its own eligible officers in rank
     * order; whoever remains eligible goes to the waiting (supplementary)
     * list. Remark codes follow §6.3: the category code on the main list, the
     * code suffixed `_W` on the waiting list.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, int>  $vacanciesByCategory
     * @return list<array<string, mixed>>
     */
    private function placeOnLists(array $entries, array $vacanciesByCategory): array
    {
        $ranked = array_values(array_filter($entries, static fn (array $e): bool => $e['rank'] !== null));
        usort($ranked, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        $filled = [];
        $mainPosition = 0;
        $waitingPosition = 0;
        $placement = [];

        foreach ($ranked as $entry) {
            $code = $entry['record']->reservationCategoryCode ?? 'UNKNOWN';
            $vacancies = $vacanciesByCategory[$code] ?? 0;
            $used = $filled[$code] ?? 0;

            if ($used < $vacancies) {
                $filled[$code] = $used + 1;
                $placement[$entry['record']->serialNumber] = [
                    'list_type' => ListType::Main,
                    'list_position' => ++$mainPosition,
                    'remark' => $code,
                ];

                continue;
            }

            $placement[$entry['record']->serialNumber] = [
                'list_type' => ListType::Waiting,
                'list_position' => ++$waitingPosition,
                'remark' => $code.'_W',
            ];
        }

        foreach ($entries as $index => $entry) {
            $sn = $entry['record']->serialNumber;
            $entries[$index]['list_type'] = $placement[$sn]['list_type'] ?? ListType::None;
            $entries[$index]['list_position'] = $placement[$sn]['list_position'] ?? null;
            /*
             | The remark an unlisted officer carries (item 24 of the change register
             | of 2026-08-27).
             |
             | A *listed* officer's remark is their reservation category — 'ST',
             | 'ST_W' — because on the promotion list the question is which seat they
             | took, not whether they were eligible. That is unchanged.
             |
             | An unlisted officer used to be flatly 'EL' or 'NE'. Item 24 names four
             | final statuses, and two of them — CIR and Rejected — say something
             | 'NE' cannot: a rejected candidate is out on a hard ground, and a CIR
             | candidate is not out at all but held. Collapsing both into 'NE' would
             | print "Not Eligible" against an officer whose promotion is merely
             | awaiting a record, which is a different and more damaging statement.
             |
             | Only the two new statuses are let through. 'EL' and 'NE' still come
             | from the score, so the PPT's vocabulary for a scored candidate is
             | untouched (items 21 and 22).
             */
            $gated = in_array(
                $entry['verdict']->remark,
                [RemarkCode::Circulation, RemarkCode::Rejected],
                true,
            );

            $entries[$index]['remark'] = $placement[$sn]['remark'] ?? ($gated
                ? $entry['verdict']->remark->value
                : ($entry['verdict']->eligible ? 'EL' : 'NE'));
        }

        return $entries;
    }

    /**
     * Q5/Q6 are unruled, so a run that met a case those rules would decide
     * records it rather than resolving it (architect ruling: never guess).
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>  $zonePending
     * @return list<string>
     */
    private function collectPendingDecisions(array $entries, array $zonePending): array
    {
        $pending = $zonePending;

        /** @var array<string, list<string>> $advisoryOfficers */
        $advisoryOfficers = [];

        foreach ($entries as $entry) {
            if ($entry['verdict']->needsBusinessDecision()) {
                $pending[] = "S.N. {$entry['record']->serialNumber} ({$entry['record']->name}): "
                    .'reserved-category relaxation may apply but is awaiting a business decision (Q5/Q6).';
            }

            /*
             | A gate's advisories are pending decisions too (BD-39).
             |
             | An advisory is a check the engine declined to apply because the rule
             | behind it is not settled. Recording it on the run is what keeps the
             | omission visible: a check quietly skipped and never mentioned is
             | indistinguishable from a check that passed.
             */
            foreach ($entry['gate']->advisories ?? [] as $advisory) {
                $advisoryOfficers[$advisory][] = "S.N. {$entry['record']->serialNumber} "
                    ."({$entry['record']->name})";
            }
        }

        /*
         | Aggregated per finding, not per officer.
         |
         | A qualification the master and the chart word differently affects *every*
         | officer under that rule, so one line each would bury the pending decisions
         | that are about one person — and a list nobody can read is a list in which a
         | real pending decision goes unnoticed.
         */
        foreach ($advisoryOfficers as $advisory => $officers) {
            $pending[] = sprintf(
                '%s Affects %d officer(s): %s. Reported and not applied — the comparison between the '
                .'Qualification Master and the chart\'s free-text qualification is awaiting a business '
                .'decision (BD-39).',
                $advisory,
                count($officers),
                implode('; ', $officers),
            );
        }

        return array_values(array_unique($pending));
    }

    /**
     * A content fingerprint of the officer data the run consumed.
     *
     * DPC proposals are not sequentially versioned (M4 design decision), so
     * "proposal version" is expressed as a hash: two runs sharing a
     * fingerprint saw identical input, and differing fingerprints prove the
     * underlying data changed between them — which is the question a
     * recalculation actually raises.
     */
    private function proposalFingerprint(DpcProposal $proposal): string
    {
        $material = $proposal->evaluationRows->map(fn ($row): array => [
            'sn' => $row->sn,
            'name' => $row->name,
            'dob' => $row->dob->toDateString(),
            'category' => $row->reservationCategory?->code,
            'tribal_years' => $row->tribal_area_service_years,
            'cr' => $row->crGrades->mapWithKeys(fn ($g): array => [$g->year => $g->grade])->sortKeys()->all(),
        ])->sortBy('sn')->values()->all();

        $material[] = [
            'vacancies' => $this->vacanciesByCategory($proposal),
            'from_class' => $proposal->promotionFromPost->class_level->value,
            'to_class' => $proposal->promotionToPost->class_level->value,
        ];

        return hash('sha256', (string) json_encode($material));
    }

    private function buildTimestamp(): ?Carbon
    {
        $configured = config('dpc.application.build_timestamp');

        if (! is_string($configured) || $configured === '') {
            return null;
        }

        try {
            return Carbon::parse($configured);
        } catch (\Throwable) {
            return null;
        }
    }
}
