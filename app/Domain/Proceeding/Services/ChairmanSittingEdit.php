<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Support\Carbon;

/**
 * The DPC Chairman's own edits at the sitting (business instruction, 2026-11-18).
 *
 * On the day the DPC is held, from its scheduled hour, the DPC Chairman may change
 * anything in any candidate's record themselves — nothing is sent back for it. Only
 * once they accepted the DPC, while it is with the committee (no return open) and its
 * proceeding is open (not yet approved, cancelled or concluded). Every edit is on the timeline, and every member's OK
 * on that candidate stops counting: the record they agreed to is not the record now.
 */
final class ChairmanSittingEdit
{
    public const EVENT = 'dpc_sitting.chairman_edit';

    /** A grading the DPC Chairman changed at the sitting, with the reason (2026-11-22). */
    public const GRADE_EVENT = 'dpc_sitting.chairman_grade_change';

    public function __construct(
        private readonly SealedCoverService $covers,
        private readonly SittingMeetingService $meetings,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /** Whether the DPC is in the state where its Chairman may edit at the sitting. */
    public function windowOpen(DpcProposal $proposal): bool
    {
        if ($proposal->status !== DpcProposalStatus::Approved || $proposal->committee_returned_at !== null) {
            return false;
        }

        // An open proceeding: none yet, or one concluded (result approved, cancelled), is no sitting to edit at.
        $latest = DpcProceeding::query()->where('dpc_proposal_id', $proposal->id)->latest('id')->first();
        if ($latest === null || ! $latest->isOpen()) {
            return false;
        }

        return app(CalculationGate::class)->chairmanAccepted($proposal) && $this->meetings->sittingOpen($proposal);
    }

    /**
     * Whether this user, as the DPC Chairman, may edit now: the DPC's current
     * allottee only — not a committee seat's flag (the fallback for a DPC never
     * allotted). The CGPSC Chairman edits as anybody else does: when they allotted
     * the DPC to themselves (2026-11-18), never by their role alone.
     */
    public function mayEdit(DpcProposal $proposal, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $allotted = DpcAllotment::query()->where('dpc_proposal_id', $proposal->id)->current()->where('allotted_to', $user->id)->exists();

        return $allotted && $this->covers->isDpcChairman($proposal, $user) && $this->windowOpen($proposal);
    }

    /** One edit made: on the timeline, and the candidate's OKs to be given again. */
    public function recorded(DpcEvaluationRow $row, string $section, User $chairman): void
    {
        $proposal = $row->proposal;

        $this->timeline->record(self::EVENT, $proposal, sprintf('%s edited %s of %s at the sitting', $chairman->name, $section, $row->name), [
            'dpc_evaluation_row_id' => $row->id,
            'section' => $section,
        ], $chairman);

        $this->meetings->invalidateCandidate($row, 'edited by the DPC Chairman at the sitting', $chairman);
    }

    /** When the DPC Chairman last edited a record at the sitting, if ever. */
    public function lastEditAt(DpcProposal $proposal): ?Carbon
    {
        $at = ActivityTimeline::query()
            ->where('event', self::EVENT)
            ->where('subject_type', $proposal->getMorphClass())
            ->where('subject_id', $proposal->id)
            ->max('occurred_at');

        return $at === null ? null : Carbon::parse((string) $at);
    }

    /**
     * A CR year's grading changed by the DPC Chairman at the sitting (2026-11-22): the
     * grading before and after, and the reason they gave, on the DPC's timeline. Nothing
     * is recorded when the save left the grading as it was.
     */
    public function gradingChanged(DpcEvaluationRow $row, int $year, ?DpcEvaluationCrGrade $before, ?DpcEvaluationCrGrade $after, string $reason, User $chairman): void
    {
        $from = self::gradingOf($before);
        $to = self::gradingOf($after);
        if ($from === $to) {
            return;
        }

        $this->timeline->record(self::GRADE_EVENT, $row->proposal, sprintf('%s changed the CR grading of %s for %d from %s to %s: %s', $chairman->name, $row->name, $year, $from, $to, $reason), [
            'dpc_evaluation_row_id' => $row->id,
            'year' => $year,
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
        ], $chairman);
    }

    /**
     * The DPC Chairman's grading changes for one candidate, latest first.
     *
     * @return list<array{year: int, from: string, to: string, reason: string, by: string|null, at: Carbon}>
     */
    public function gradeChanges(DpcEvaluationRow $row): array
    {
        $entries = ActivityTimeline::query()
            ->with('causer')
            ->where('event', self::GRADE_EVENT)
            ->where('subject_type', $row->proposal->getMorphClass())
            ->where('subject_id', $row->dpc_proposal_id)
            ->latest('occurred_at')->latest('id')
            ->get();

        $changes = [];
        foreach ($entries as $entry) {
            $properties = $entry->properties ?? [];
            if ((int) ($properties['dpc_evaluation_row_id'] ?? 0) !== $row->id) {
                continue;
            }
            $causer = $entry->causer;
            $changes[] = [
                'year' => (int) ($properties['year'] ?? 0),
                'from' => (string) ($properties['from'] ?? ''),
                'to' => (string) ($properties['to'] ?? ''),
                'reason' => (string) ($properties['reason'] ?? ''),
                'by' => $causer instanceof User ? $causer->name : null,
                'at' => Carbon::parse((string) $entry->occurred_at),
            ];
        }

        return $changes;
    }

    /** A CR year's grading in words: Missing, NG, or its grades. */
    private static function gradingOf(?DpcEvaluationCrGrade $cr): string
    {
        if ($cr === null) {
            return '—';
        }
        if ($cr->put_up === false) {
            return 'Missing';
        }
        if ($cr->has_grading === false) {
            return 'NG';
        }
        $grades = array_values(array_filter(array_map(fn (array $report): string => (string) ($report['grade'] ?? ''), (array) ($cr->reports ?? []))));

        return $grades !== [] ? implode(', ', $grades) : (string) ($cr->grade ?? '—');
    }
}
