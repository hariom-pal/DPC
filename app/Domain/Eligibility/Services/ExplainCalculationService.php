<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;

/**
 * Explain Result (architect directive) — M5 Phase 3.
 *
 * Turns a stored calculation into plain language, **entirely from what the
 * run recorded**. No AI. No inference. Nothing is recomputed, and nothing is
 * read from current configuration: every number, threshold and grade quoted
 * below comes out of the run's own snapshot.
 *
 * That constraint is the point. An explanation that recomputed anything could
 * disagree with the result it claims to explain — after a rule change it
 * certainly would — and an explanation that disagrees with the decision it
 * accompanies is worse than none at all in a service matter.
 */
class ExplainCalculationService
{
    /**
     * Why this officer ended where they did.
     *
     * @return list<string>
     */
    public function explainResult(DpcCalculationResult $result): array
    {
        $run = $result->run;
        $score = $result->score_breakdown;
        $verdict = $result->verdict;
        $lines = [];

        $lines[] = "Officer: {$result->officer_name} (S.N. {$result->sn}, category {$result->reservation_category_code}).";
        $lines[] = 'Calculated under rule version '.$run->rule_version.' on '.$run->executed_at->format('d-m-Y H:i').'.';

        // Scoring, year by year, exactly as recorded.
        $years = $score['years_used'] ?? [];
        if ($years === []) {
            $lines[] = 'No graded CR year was on record, so no score could be computed.';
        } else {
            $parts = [];
            foreach ($years as $year) {
                $parts[] = $year.' = '.($score['marks_by_year'][$year] ?? $score['marks_by_year'][(string) $year] ?? '—');
            }
            $lines[] = 'CR years scored ('.count($years).'): '.implode(', ', $parts).'. Base total '.$score['base_total'].'.';
        }

        if (($score['tribal_area_bonus'] ?? 0) > 0) {
            $lines[] = 'Tribal-area service bonus of '.$score['tribal_area_bonus'].' applied, giving a total of '.$score['total'].'.';
        }

        if (! empty($score['years_without_grade'])) {
            $lines[] = 'Years on the chart with no grade recorded: '.implode(', ', $score['years_without_grade'])
                .'. These were not scored as zero.';
        }

        if (! empty($score['incomplete_window'])) {
            $lines[] = 'Fewer graded CR years were available than the '.$score['scoring_years_required']
                .' the rules score over.';
        }

        /*
         | Verdict — stated as one of item 24's four statuses (change register of
         | 2026-08-27), not as a boolean.
         |
         | This screen is the one an officer is shown when they ask why they were not
         | promoted, and it is the one a tribunal reads. "Not eligible" against a
         | candidate whose promotion is merely *held* pending a record would be the
         | wrong answer in the one place it matters most.
         */
        $status = $result->status();

        $lines[] = match ($status) {
            RemarkCode::Eligible, RemarkCode::SuperSeat => 'Eligible: scored '.$verdict['achieved_score']
                .' against a requirement of '.$verdict['required_score'].'.',

            RemarkCode::Circulation => 'CIR — the promotion is held, not refused: a mandatory record is '
                .'unavailable and has been justified with a reason and a supporting document. The candidate '
                .'continues in this DPC. Scored '.$verdict['achieved_score'].' against a requirement of '
                .$verdict['required_score'].'.',

            RemarkCode::Rejected => 'Rejected. Scored '.$verdict['achieved_score'].' against a requirement of '
                .$verdict['required_score'].', but the rejection stands on the ground(s) below whatever the '
                .'marks were.',

            RemarkCode::NotEligible => 'Not eligible.',
        };

        foreach ($verdict['failure_reasons'] ?? [] as $reason) {
            $lines[] = 'Reason: '.$reason;
        }

        // Which record is being awaited (item 19). "CIR" on its own does not say.
        foreach ($result->gateCitations() as $citation) {
            $lines[] = 'Awaiting: '.$citation;
        }

        if ($result->is_super_seat) {
            $lines[] = 'Super Seat: '.($result->super_seat['explanation'] ?? 'all CR years at the required grade.');
        }

        // Zone and placement.
        $lines[] = $result->in_zone
            ? 'Within the zone of consideration. '.($run->zone['explanation'] ?? '')
            : 'Outside the zone of consideration. '.($run->zone['explanation'] ?? '');

        $lines[] = match ($result->list_type->value) {
            'main' => "Placed on the Main List at position {$result->list_position}, remark {$result->remark}.",
            'waiting' => "Placed on the Waiting List at position {$result->list_position}, remark {$result->remark}.",
            default => 'Not placed on either list.'.($result->is_eligible && ! $result->in_zone
                ? ' The officer is eligible but fell outside the zone of consideration.'
                : ''),
        };

        if (! empty($verdict['reserved_relaxation_pending'])) {
            $lines[] = 'This outcome is affected by a rule awaiting a business decision (Q5/Q6): the '
                .'reserved-category relaxation was not applied.';
        }

        return array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    /**
     * How the run as a whole reached its lists, including the provenance a
     * challenge would ask for.
     *
     * @return list<string>
     */
    public function explainRun(DpcCalculationRun $run): array
    {
        // executed_by is nullable (a run may be produced by a system process),
        // so the relation is resolved before use rather than chained.
        $executor = $run->executedBy;

        $lines = [
            'Calculation '.$run->uuid.', executed '.$run->executed_at->format('d-m-Y H:i')
                .' by '.($executor instanceof User ? $executor->name : 'system').'.',
            'Rule version '.$run->rule_version.'.',
            'Produced by application version '.$run->application_version.' (commit '.$run->git_commit_hash.')'
                .($run->build_timestamp !== null ? ', built '.$run->build_timestamp->format('d-m-Y H:i') : '').'.',
            'Officer data fingerprint '.substr($run->proposal_version, 0, 16).'… — a different fingerprint means the '
                .'underlying proposal data changed.',
            (string) ($run->zone['explanation'] ?? ''),
            $run->officers_considered.' officer(s) assessed; '.$run->eligible_count.' eligible; '
                .$run->main_list_count.' on the Main List; '.$run->waiting_list_count.' on the Waiting List.',
        ];

        if ($run->has_pending_decisions) {
            $lines[] = 'This calculation met case(s) governed by a rule awaiting a business decision. '
                .'The printed rules were applied and the affected cases are listed below.';

            foreach ($run->pending_decisions ?? [] as $pending) {
                $lines[] = '• '.$pending;
            }
        }

        return array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }
}
