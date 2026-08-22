<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcSeniorityEntry;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Facades\DB;

/**
 * The Seniority List is the source of truth for **who** is evaluated (business
 * directive, 2026-08-22).
 *
 * **The problem this removes.** S16, S17 and S18 each carried the same ten
 * employee fields, so a department typed or uploaded the same officer three
 * times. Nothing reconciled them, which meant an officer could be `US4` with one
 * date of birth on the seniority list and another on the evaluation chart — and
 * the scoring engine would use the chart's. The chart decides promotions, so the
 * copy that was least verified was the one that counted.
 *
 * **The flow now.** Importing the Seniority List *projects* an evaluation row per
 * officer, with all ten identity fields copied across. The Authorized Officer
 * then supplies only what evaluation actually needs — CR grades, loyalty,
 * property, tribal-area service and a note — and cannot add, remove or rename an
 * officer.
 *
 * **Identity is still copied onto `dpc_evaluation_rows`, not read through a
 * relation, and that is deliberate.** Every officer table in this application is
 * a point-in-time snapshot with no live foreign key (the ten-field standard), so
 * that a historical proposal cannot change because a record was edited later. A
 * relation would have made the chart's identity follow the seniority list
 * forever, including after approval — losing exactly the property the snapshot
 * design exists to provide, and requiring a schema change to get there. Copying
 * keeps the evidence immutable and needs no migration.
 *
 * **What re-importing a seniority list does.** The candidate set is rebuilt.
 * Evaluation data already entered is carried over for officers who are still
 * there and discarded for those who are not — see {@see keyOf()} for how an
 * officer is recognised across the two lists, which is the part worth reading.
 */
class EvaluationCandidateService
{
    /**
     * Rebuild the evaluation candidate list from the seniority list.
     *
     * Idempotent: running it twice with an unchanged seniority list leaves the
     * chart exactly as it was, evaluation data included. That matters because
     * {@see DpcProposalService::saveSeniorityEntries()} calls it on every save,
     * and an officer's CR grades must not evaporate because somebody corrected a
     * spelling on the seniority list.
     */
    public function project(DpcProposal $proposal): void
    {
        DB::transaction(function () use ($proposal): void {
            $entries = $proposal->seniorityEntries()->orderBy('seniority_number')->get();
            $preserved = $this->existingEvaluationData($proposal);

            $proposal->evaluationRows()->delete();

            foreach ($entries as $entry) {
                $row = $proposal->evaluationRows()->create($this->identityFrom($entry));

                $carried = $preserved[$this->keyOf(
                    $entry->employee_number,
                    (int) $entry->seniority_number,
                    (string) $entry->name,
                )] ?? null;

                if ($carried === null) {
                    continue;
                }

                $this->writeEvaluationData($row, $carried);
            }
        });
    }

    /**
     * Replace the evaluation data on the projected rows, leaving identity alone.
     *
     * The officer set is asserted first, so a payload that adds, drops or renames
     * an officer is refused before anything is written rather than silently
     * reconciled.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function applyEvaluationData(DpcProposal $proposal, array $rows): void
    {
        $this->assertMatchesCandidates($proposal, $rows);

        DB::transaction(function () use ($proposal, $rows): void {
            $bySn = $proposal->evaluationRows()->get()->keyBy(fn (DpcEvaluationRow $row): int => (int) $row->sn);

            foreach ($rows as $incoming) {
                $row = $bySn->get((int) $incoming['sn']);

                if (! $row instanceof DpcEvaluationRow) {
                    continue; // Unreachable after assertMatchesCandidates().
                }

                $row->forceFill([
                    'tribal_area_service_years' => $incoming['tribal_area_service_years'] ?? null,
                    'note' => $incoming['note'] ?? null,
                ])->save();

                $row->crGrades()->delete();
                $row->loyaltyFlags()->delete();
                $row->propertyFlags()->delete();

                $this->writeEvaluationData($row, [
                    'cr_grades' => $incoming['cr_grades'] ?? [],
                    'loyalty_flags' => $incoming['loyalty_flags'] ?? [],
                    'property_flags' => $incoming['property_flags'] ?? [],
                ]);
            }
        });
    }

    /**
     * How many officers the chart must carry: the approved Seniority List's
     * length.
     *
     * **The Seniority List rather than the Zone of Consideration**, even though
     * the directive words it as the zone. The two are equal wherever a zone
     * applies, because the list is already gated to match it — but a class pair
     * the deck gives no zone formula has no zone at all, and the chart still has
     * to match the list. Taking the count from the list is right in both cases;
     * taking it from the zone would leave the uncapped ones ungoverned.
     */
    public function expectedCount(DpcProposal $proposal): int
    {
        return $proposal->seniorityEntries()->count();
    }

    /**
     * @return array{expected: int, present: int, matches: bool, has_candidates: bool}
     */
    public function summary(DpcProposal $proposal): array
    {
        $expected = $this->expectedCount($proposal);
        $present = $proposal->evaluationRows()->count();

        return [
            'expected' => $expected,
            'present' => $present,
            'matches' => $expected > 0 && $expected === $present,
            'has_candidates' => $expected > 0,
        ];
    }

    /**
     * The one wording for a chart that does not match its seniority list.
     *
     * Shared by the Form Request, the Excel commit, the service layer and the
     * submission checklist, so the same fault reads the same way whichever route
     * an officer meets it on.
     */
    public function mismatchMessage(): string
    {
        return 'The Evaluation Chart must contain exactly the same officers as the approved Seniority List.';
    }

    /**
     * Refuse a payload whose officers are not exactly the seniority list's.
     *
     * Three distinct faults, reported distinctly, because "the list changed" is
     * not actionable on its own:
     *
     *   - a different number of rows;
     *   - the same number of rows but a different set of serial numbers;
     *   - the right officer with a different employee number, which is how an
     *     edited spreadsheet quietly reassigns somebody's confidential record.
     *
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws WorkflowException
     */
    public function assertMatchesCandidates(DpcProposal $proposal, array $rows): void
    {
        foreach ($this->violationsFor($proposal, $rows) as $violation) {
            throw new WorkflowException($violation);
        }
    }

    /**
     * The same check, as a list of messages, for the Form Request and the
     * submission checklist — neither of which wants an exception.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    public function violationsFor(DpcProposal $proposal, array $rows): array
    {
        $entries = $proposal->seniorityEntries()->get();

        if ($entries->isEmpty()) {
            return ['The Seniority List must be uploaded before the Evaluation Chart can be filled in.'];
        }

        $expected = $entries
            ->keyBy(fn (DpcSeniorityEntry $entry): int => (int) $entry->seniority_number)
            ->map(fn (DpcSeniorityEntry $entry): ?string => $entry->employee_number);

        $submitted = [];

        foreach ($rows as $row) {
            $submitted[(int) ($row['sn'] ?? 0)] = $row['employee_number'] ?? null;
        }

        if (count($submitted) !== $expected->count()) {
            return [$this->mismatchMessage()
                ." It carries {$expected->count()} officer(s) on the Seniority List and ".count($submitted).' in this chart.'];
        }

        $missing = array_diff($expected->keys()->all(), array_keys($submitted));
        $extra = array_diff(array_keys($submitted), $expected->keys()->all());

        if ($missing !== [] || $extra !== []) {
            $detail = [];

            if ($missing !== []) {
                $detail[] = 'missing S.N. '.implode(', ', $missing);
            }

            if ($extra !== []) {
                $detail[] = 'unexpected S.N. '.implode(', ', $extra);
            }

            return [$this->mismatchMessage().' ('.implode('; ', $detail).')'];
        }

        // Employee numbers: compared only where the Seniority List recorded one.
        // A blank on the list is not evidence of anything, and refusing a chart
        // for filling one in would punish a department for adding information.
        $renamed = [];

        foreach ($expected as $sn => $employeeNumber) {
            if ($employeeNumber === null || $employeeNumber === '') {
                continue;
            }

            $incoming = $submitted[$sn] ?? null;

            if ($incoming !== null && $incoming !== '' && (string) $incoming !== (string) $employeeNumber) {
                $renamed[] = "S.N. {$sn} (Seniority List has {$employeeNumber}, chart has {$incoming})";
            }
        }

        if ($renamed !== []) {
            return ['Employee numbers cannot be changed on the Evaluation Chart: '.implode('; ', $renamed).'.'];
        }

        return [];
    }

    /**
     * The ten identity fields, copied from the seniority entry.
     *
     * `sn` takes the seniority number, so the chart is in seniority order and
     * every downstream reference to "S.N. 4" means the same officer on both
     * screens. Before this it was a free-standing serial the department typed
     * again, and the two could disagree.
     *
     * @return array<string, mixed>
     */
    private function identityFrom(DpcSeniorityEntry $entry): array
    {
        return [
            'sn' => $entry->seniority_number,
            'employee_number' => $entry->employee_number,
            'name' => $entry->name,
            'dob' => $entry->dob,
            'qualification' => $entry->qualification,
            'current_post' => $entry->current_post,
            'feeder_post' => $entry->feeder_post,
            'reservation_category_id' => $entry->reservation_category_id,
            'date_of_joining_service' => $entry->date_of_joining_service,
            'date_of_posting_on_current_post' => $entry->date_of_posting_on_current_post,
            'date_of_posting_on_feeder_post' => $entry->date_of_posting_on_feeder_post,
        ];
    }

    /**
     * Evaluation data already on the chart, keyed so it can be matched back to a
     * re-imported seniority list.
     *
     * @return array<string, array<string, mixed>>
     */
    private function existingEvaluationData(DpcProposal $proposal): array
    {
        $carried = [];

        $rows = $proposal->evaluationRows()
            ->with(['crGrades', 'loyaltyFlags', 'propertyFlags'])
            ->get();

        foreach ($rows as $row) {
            $carried[$this->keyOf($row->employee_number, (int) $row->sn, (string) $row->name)] = [
                'tribal_area_service_years' => $row->tribal_area_service_years,
                'note' => $row->note,
                'cr_grades' => $row->crGrades
                    ->map(fn ($g): array => ['year' => $g->year, 'grade' => $g->grade])->all(),
                'loyalty_flags' => $row->loyaltyFlags
                    ->map(fn ($f): array => ['year' => $f->year, 'flag' => $f->flag])->all(),
                'property_flags' => $row->propertyFlags
                    ->map(fn ($f): array => ['year' => $f->year, 'flag' => $f->flag])->all(),
            ];
        }

        return $carried;
    }

    /**
     * How an officer is recognised across two versions of the seniority list.
     *
     * **Employee number first, and the serial number only as a fallback.** A
     * serial number is a *position*, not a person: re-order the list, or remove
     * one officer from the middle of it, and S.N. 4 is somebody else. Carrying
     * evaluation data across on serial alone would move one officer's CR grades
     * onto another's row — the worst available outcome, because it is invisible
     * and it decides a promotion.
     *
     * Where a department records no employee number, `sn|name` is the best key
     * available. It is imperfect — two officers of the same name at the same
     * position are indistinguishable — but it fails towards *discarding* data
     * rather than misattributing it, because a changed name produces a fresh key
     * and an empty row the officer must fill in again.
     */
    private function keyOf(?string $employeeNumber, int $sn, string $name): string
    {
        if ($employeeNumber !== null && $employeeNumber !== '') {
            return 'emp:'.$employeeNumber;
        }

        return 'pos:'.$sn.'|'.mb_strtolower(trim($name));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeEvaluationData(DpcEvaluationRow $row, array $data): void
    {
        if (array_key_exists('tribal_area_service_years', $data) || array_key_exists('note', $data)) {
            $row->forceFill([
                'tribal_area_service_years' => $data['tribal_area_service_years'] ?? null,
                'note' => $data['note'] ?? null,
            ])->save();
        }

        /** @var array<int, array<string, mixed>> $crGrades */
        $crGrades = $data['cr_grades'] ?? [];

        foreach ($crGrades as $grade) {
            $row->crGrades()->create(['year' => $grade['year'], 'grade' => $grade['grade'] ?? null]);
        }

        /** @var array<int, array<string, mixed>> $loyalty */
        $loyalty = $data['loyalty_flags'] ?? [];

        foreach ($loyalty as $flag) {
            $row->loyaltyFlags()->create(['year' => $flag['year'], 'flag' => $flag['flag'] ?? null]);
        }

        /** @var array<int, array<string, mixed>> $property */
        $property = $data['property_flags'] ?? [];

        foreach ($property as $flag) {
            $row->propertyFlags()->create(['year' => $flag['year'], 'flag' => $flag['flag'] ?? null]);
        }
    }
}
