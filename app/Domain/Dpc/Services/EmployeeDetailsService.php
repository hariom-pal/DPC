<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Enums\PhReservationOutcome;
use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Dpc\Models\DpcEvaluationDocument;
use App\Domain\Dpc\Models\DpcEvaluationPropertyFlag;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Services\DpcRuleBook;
use App\Domain\Master\Services\PhReservationPolicy;
use App\Domain\Master\Support\ReservationMode;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Employee-wise details inside the Authorized Officer's DPC proposal (Coder
 * Instructions, points 2 and 3, rule 4.1 and the undertakings on pages 3 and 5).
 *
 * Everything here is data entry by the Authorized Officer on the proposal the
 * department already owns — there is no employee login, no new role and no separate
 * workflow. The engine keeps reading what it always read: one grade per CR year,
 * one filed/not-filed flag per IPR year, the officer's category. This service writes
 * those values from the questionnaires and records the evidence beside them.
 *
 * ### What is deliberately not decided here
 *
 * - How many CR years are required, and what a missing year does to eligibility —
 *   the existing engine settings (`dpc.merit.required_cr_count`, the gate order of
 *   the change register) still decide; this screen only records the year.
 * - The page-3 instruction to "take one previous year CR" when a CR was not put up
 *   or carries no grading. It conflicts with the change register's CIR treatment of a
 *   missing record, so the year is recorded as missing and the conflict is reported.
 * - Whether a PH claim is verified and granted. A claim is recorded as a claim.
 */
class EmployeeDetailsService
{
    public function __construct(
        private readonly ActivityTimelineService $timeline,
        private readonly DpcRuleBook $ruleBook,
    ) {}

    // ------------------------------------------------------------------
    // A. Basic information
    // ------------------------------------------------------------------

    /**
     * The fields the simplified Seniority List does not carry, recorded by the
     * Authorized Officer. Name, date of birth, Employee ID and seniority number stay
     * the list's and are not editable here.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveBasic(DpcEvaluationRow $row, array $data, User $actor): void
    {
        $this->assertEditable($row);

        $row->forceFill([
            'reservation_category_id' => $data['reservation_category_id'] ?? null,
            'qualification' => $this->blankToNull($data['qualification'] ?? null),
            'mobile' => $this->blankToNull($data['mobile'] ?? null),
            'date_of_posting_on_promotion_from_post' => $data['date_of_posting_on_promotion_from_post'] ?? null,
        ])->save();

        $this->log($row, 'basic_saved', 'Basic information saved', [], $actor);
    }

    // ------------------------------------------------------------------
    // C. Confidential Reports
    // ------------------------------------------------------------------

    /**
     * One CR year, answered the way page 3 asks: was a CR put up; does it carry a
     * grading; and, if so, the reports written in the year (at most four), each with
     * the reporting, reviewing and accepting officers' designations and grades.
     *
     * The year's grade — the one value the engine reads — is derived, never typed:
     * each report's final grade is the accepting officer's, else the reviewer's, else
     * the reporter's; several reports are averaged on the configured marks and the
     * decimal part dropped (rule 3.1). A year without a grading, or with no CR put up,
     * keeps no grade.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveCrYear(DpcEvaluationRow $row, int $year, array $data, User $actor): DpcEvaluationCrGrade
    {
        $this->assertEditable($row);
        $this->assertYear($row, $year);

        $putUp = (bool) $data['put_up'];
        $hasGrading = $putUp ? (bool) ($data['has_grading'] ?? false) : null;
        $reports = $putUp && $hasGrading ? $this->normaliseReports($data['reports'] ?? []) : null;

        if ($reports === []) {
            throw new WorkflowException(__('dpc.employee_details.cr.reports_required'));
        }

        $grade = $reports === null ? null : $this->yearGrade($reports, $this->ruleBook->gradeMarksFor($row->proposal));

        /** @var DpcEvaluationCrGrade $record */
        $record = $row->crGrades()->firstOrNew(['year' => $year]);
        $record->forceFill([
            'put_up' => $putUp,
            'has_grading' => $hasGrading,
            'reports' => $reports,
            'grade' => $grade,
        ])->save();

        $this->log($row, 'cr_year_saved', sprintf('CR %d recorded: %s', $year, $grade ?? ($putUp ? 'no grading' : 'not put up')), [
            'year' => $year,
            'put_up' => $putUp,
            'has_grading' => $hasGrading,
            'grade' => $grade,
        ], $actor);

        return $record;
    }

    public function removeCrYear(DpcEvaluationRow $row, int $year, User $actor): void
    {
        $this->assertEditable($row);

        $row->crGrades()->where('year', $year)->delete();

        $this->log($row, 'cr_year_removed', "CR {$year} removed", ['year' => $year], $actor);
    }

    /**
     * Page 3: "take undertaking that CR is signed as per channel also ask to upload
     * channel document". Both together, so neither stands without the other.
     */
    public function confirmChannel(DpcEvaluationRow $row, UploadedFile $file, User $actor): DpcEvaluationDocument
    {
        $this->assertEditable($row);

        return DB::transaction(function () use ($row, $file, $actor): DpcEvaluationDocument {
            $document = $this->storeDocument($row, EvaluationDocumentKind::CrChannel, null, $file, true, null, $actor);

            $row->forceFill([
                'cr_channel_undertaking_by' => $actor->id,
                'cr_channel_undertaking_at' => now(),
            ])->save();

            return $document;
        });
    }

    /**
     * The grade a year's reports come to (rule 3.1).
     *
     * @param  list<array<string, string|null>>  $reports
     * @param  array<string, int>|null  $marks  the scale of the proposal's year; configuration when null
     */
    public function yearGrade(array $reports, ?array $marks = null): string
    {
        $marks ??= array_map('intval', (array) config('dpc.scoring.cr_grade_marks'));
        $finals = array_map(fn (array $report): string => (string) $this->finalGrade($report), $reports);

        if (count($finals) === 1) {
            return $finals[0];
        }

        $sum = 0;

        foreach ($finals as $grade) {
            $sum += $marks[$grade];
        }

        // "If there is any decimal part then it should be omitted without changing
        // the integer part" — truncation, never rounding.
        $average = intdiv($sum, count($finals));
        $grade = array_search($average, $marks, true);

        if (! is_string($grade)) {
            throw new WorkflowException(__('dpc.employee_details.cr.average_unmappable', ['mark' => $average]));
        }

        return $grade;
    }

    /**
     * Page 3: the final remark is the accepting officer's, else the reviewer's, else
     * the reporter's.
     *
     * @param  array<string, string|null>  $report
     */
    public function finalGrade(array $report): ?string
    {
        return $report['accepting_grade'] ?? $report['reviewer_grade'] ?? $report['reporter_grade'] ?? null;
    }

    /**
     * Page 3: an NRC is compulsory when any of the three remarks is missing.
     *
     * @param  array<string, string|null>  $report
     */
    public function reportNeedsNrc(array $report): bool
    {
        return ($report['reporter_grade'] ?? null) === null
            || ($report['reviewer_grade'] ?? null) === null
            || ($report['accepting_grade'] ?? null) === null;
    }

    // ------------------------------------------------------------------
    // D. Property Return (rule 4.1)
    // ------------------------------------------------------------------

    /**
     * One IPR year: submitted or not, the submission date, the date of next
     * increment printed on it (page 4), and — for a return submitted after 31 January
     * of the following year — the department's decision.
     *
     * `flag`, which the engine reads, is true only for a return that counts: submitted,
     * and not refused by the department as late.
     *
     * @param  array<string, mixed>  $data
     */
    public function savePropertyYear(DpcEvaluationRow $row, int $year, array $data, User $actor): DpcEvaluationPropertyFlag
    {
        $this->assertEditable($row);
        $this->assertYear($row, $year);

        $submitted = (bool) $data['submitted'];
        $submittedOn = $submitted ? Carbon::parse((string) $data['submitted_on'])->startOfDay() : null;
        $nextIncrement = $submitted && ($data['next_increment_date'] ?? null) !== null
            ? Carbon::parse((string) $data['next_increment_date'])->startOfDay()
            : null;

        $late = $submittedOn !== null && $submittedOn->gt(DpcEvaluationPropertyFlag::dueDateFor($year));
        $decision = $late ? ($data['late_decision'] ?? null) : null;

        if ($nextIncrement !== null) {
            $clash = $row->propertyFlags()
                ->where('year', '!=', $year)
                ->whereDate('next_increment_date', $nextIncrement->toDateString())
                ->value('year');

            if ($clash !== null) {
                throw new WorkflowException(__('dpc.employee_details.property.duplicate_increment', [
                    'date' => $nextIncrement->format('d-m-Y'),
                    'year' => $clash,
                ]));
            }
        }

        /** @var DpcEvaluationPropertyFlag $record */
        $record = DpcEvaluationPropertyFlag::query()->firstOrNew([
            'dpc_evaluation_row_id' => $row->id,
            'year' => $year,
        ]);

        $record->forceFill([
            'flag' => $submitted && $decision !== DpcEvaluationPropertyFlag::LATE_REJECTED,
            'submitted_on' => $submittedOn,
            'next_increment_date' => $nextIncrement,
            'late_decision' => $decision,
            'late_decision_remarks' => $late ? $this->blankToNull($data['late_decision_remarks'] ?? null) : null,
        ])->save();

        $this->log($row, 'property_year_saved', sprintf('Property Return %d recorded: %s', $year, $submitted ? 'submitted' : 'not submitted'), [
            'year' => $year,
            'submitted' => $submitted,
            'submitted_on' => $submittedOn?->toDateString(),
            'late_decision' => $decision,
        ], $actor);

        return $record;
    }

    public function removePropertyYear(DpcEvaluationRow $row, int $year, User $actor): void
    {
        $this->assertEditable($row);

        $row->propertyFlags()->where('year', $year)->delete();

        $this->log($row, 'property_year_removed', "Property Return {$year} removed", ['year' => $year], $actor);
    }

    // ------------------------------------------------------------------
    // E. PH reservation (rule 2.1)
    // ------------------------------------------------------------------

    /**
     * The three questions, each asked only when the previous answer leads to it.
     *
     * Answers that the branch does not reach are cleared rather than kept, so a
     * changed answer can never leave a stale "claims reservation = yes" behind a
     * "not PH". The claim position, the verification status and the final decision
     * are three separate columns: a claim sets verification to `pending` and leaves
     * the decision open; cases 1 to 3 record that no benefit is given.
     *
     * @param  array<string, mixed>  $data
     */
    public function savePh(DpcEvaluationRow $row, array $data, User $actor): PhReservationOutcome
    {
        $this->assertEditable($row);

        $isPh = (bool) $data['is_ph'];
        $previously = $isPh ? (bool) $data['ph_previously_availed'] : null;
        $claims = $isPh && $previously === false ? (bool) $data['ph_claims_reservation'] : null;

        $outcome = PhReservationOutcome::from3($isPh, $previously, $claims);

        $reason = match ($outcome) {
            PhReservationOutcome::PreviouslyAvailed => $this->blankToNull($data['ph_ineligibility_reason'] ?? null)
                ?? (string) __('dpc.employee_details.ph.default_previously_availed_reason'),
            default => null,
        };

        $row->forceFill([
            'is_ph' => $isPh,
            'ph_previously_availed' => $previously,
            'ph_claims_reservation' => $claims,
            'ph_ineligibility_reason' => $reason,
            'ph_verification_status' => $outcome->isClaim() ? 'pending' : null,
            'ph_final_decision' => $outcome->isClaim() ? null : 'no_benefit',
            'ph_recorded_by' => $actor->id,
            'ph_recorded_at' => now(),
        ])->save();

        $this->log($row, 'ph_recorded', 'PH questionnaire: '.$outcome->value, [
            'is_ph' => $isPh,
            'previously_availed' => $previously,
            'claims_reservation' => $claims,
            'outcome' => $outcome->value,
        ], $actor);

        return $outcome;
    }

    // ------------------------------------------------------------------
    // F. Documents
    // ------------------------------------------------------------------

    /**
     * Upload one employee-wise document. A previous document of the same kind for the
     * same year is superseded, never deleted — except "other", which accumulates.
     */
    public function upload(
        DpcEvaluationRow $row,
        EvaluationDocumentKind $kind,
        ?int $year,
        UploadedFile $file,
        bool $undertaking,
        ?string $remarks,
        User $actor,
    ): DpcEvaluationDocument {
        $this->assertEditable($row);

        if ($kind === EvaluationDocumentKind::CrChannel) {
            // The document is the evidence for the undertaking; neither stands alone.
            if (! $undertaking) {
                throw new WorkflowException(__('dpc.employee_details.documents.undertaking_required'));
            }

            return $this->confirmChannel($row, $file, $actor);
        }

        return $this->storeDocument($row, $kind, $year, $file, $undertaking, $remarks, $actor);
    }

    // ------------------------------------------------------------------
    // G. Completion status
    // ------------------------------------------------------------------

    /**
     * What is still missing for one officer, section by section.
     *
     * Only what the Coder Instructions and the existing rules require is asked for.
     * How many CR or IPR years an officer needs is **not** asked here — the engine's
     * existing settings judge that — so the CR and Property sections need at least
     * one year recorded and every recorded year to be fully answered and evidenced.
     *
     * @return array{complete: bool, sections: array<string, list<string>>, summary: string}
     */
    public function status(DpcEvaluationRow $row): array
    {
        $row->loadMissing(['crGrades', 'propertyFlags', 'recordGaps', 'currentDocuments']);

        $sections = [
            'basic' => $this->basicMissing($row),
            'cr' => $this->crMissing($row),
            'property' => $this->propertyMissing($row),
            'ph' => $this->phMissing($row),
        ];

        $complete = array_merge(...array_values($sections)) === [];

        $summary = $complete
            ? (string) __('dpc.employee_details.status.complete')
            : implode(', ', array_map(
                static fn (string $key): string => (string) __("dpc.employee_details.status.pending.{$key}"),
                array_keys(array_filter($sections, static fn (array $missing): bool => $missing !== [])),
            ));

        return ['complete' => $complete, 'sections' => $sections, 'summary' => $summary];
    }

    /**
     * Every officer's status, in seniority order.
     *
     * @return Collection<int, array{row: DpcEvaluationRow, status: array{complete: bool, sections: array<string, list<string>>, summary: string}}>
     */
    public function statuses(DpcProposal $proposal): Collection
    {
        return $proposal->evaluationRows()
            ->with(['crGrades', 'propertyFlags', 'recordGaps', 'currentDocuments', 'reservationCategory'])
            ->get()
            ->map(fn (DpcEvaluationRow $row): array => ['row' => $row, 'status' => $this->status($row)])
            ->values();
    }

    /**
     * Rule 2.1's "system verification … whether the PH benefit has been availed of
     * previously": the same officer's PH record in this department's other DPC
     * proposals (matched by Employee ID), shown beside the questionnaire.
     *
     * Reported, never applied: a claim recorded on another proposal is not proof the
     * benefit was granted, so the Authorized Officer's answer stands and the
     * verifier sees both.
     *
     * @return Collection<int, DpcEvaluationRow>
     */
    public function earlierPhRecords(DpcEvaluationRow $row): Collection
    {
        if ($row->employee_number === null || $row->employee_number === '') {
            return new Collection;
        }

        $departmentId = $row->proposal->department_id;

        return DpcEvaluationRow::query()
            ->where('employee_number', $row->employee_number)
            ->where('dpc_proposal_id', '!=', $row->dpc_proposal_id)
            ->where('is_ph', true)
            ->whereHas('proposal', fn ($query) => $query
                ->where('department_id', $departmentId)
                ->where('status', '!=', DpcProposalStatus::Cancelled->value))
            ->with('proposal')
            ->orderByDesc('id')
            ->get()
            ->toBase();
    }

    /**
     * Rule 2.1: a share of the promotion vacancies (3%), whole number only — "the
     * decimal part is disregarded even if it is .9". The percentage and the rounding
     * are the PH Reservation Policy in force on the proposal date. Displayed beside
     * the officers; the engine's list placement is not changed by it.
     *
     * @return array{percent: string, vacancies: int, posts: int, claims: int}
     */
    public function phQuota(DpcProposal $proposal): array
    {
        $ph = app(PhReservationPolicy::class);
        $terms = $ph->termsFor($proposal);
        $vacancies = (int) $proposal->vacancies()->sum('count');

        return [
            'percent' => rtrim(rtrim($terms['percentage'], '0'), '.'),
            'vacancies' => $vacancies,
            'posts' => $ph->posts($vacancies, $terms['percentage'], $terms['rounding']),
            'claims' => $proposal->evaluationRows()
                ->where('is_ph', true)
                ->where('ph_previously_availed', false)
                ->where('ph_claims_reservation', true)
                ->count(),
        ];
    }

    // ------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function basicMissing(DpcEvaluationRow $row): array
    {
        $missing = [];

        if (app(ReservationMode::class)->categoryWise()
            && (bool) config('dpc.employee_details.require_category', true) && $row->reservation_category_id === null) {
            $missing[] = (string) __('dpc.employee_details.missing.category');
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function crMissing(DpcEvaluationRow $row): array
    {
        $missing = [];
        $requireDocuments = (bool) config('dpc.employee_details.require_cr_documents', true);

        if ($row->crGrades->isEmpty()) {
            return [(string) __('dpc.employee_details.missing.cr_none')];
        }

        $anyPutUp = false;

        foreach ($row->crGrades as $cr) {
            $year = $cr->year;

            if ($cr->put_up === null) {
                $missing[] = (string) __('dpc.employee_details.missing.cr_unanswered', ['year' => $year]);

                continue;
            }

            if ($cr->put_up === false) {
                // Rule 3.2: the reason, with its document — the existing
                // missing-record justification.
                if (! $this->hasJustifiedGap($row, $year)) {
                    $missing[] = (string) __('dpc.employee_details.missing.cr_reason', ['year' => $year]);
                }

                continue;
            }

            $anyPutUp = true;

            if ($cr->has_grading === false) {
                if (! $this->hasDocument($row, EvaluationDocumentKind::CrWithoutGrading, $year)) {
                    $missing[] = (string) __('dpc.employee_details.missing.cr_without_grading_document', ['year' => $year]);
                }

                continue;
            }

            if ($requireDocuments && ! $this->hasDocument($row, EvaluationDocumentKind::CrReport, $year)) {
                $missing[] = (string) __('dpc.employee_details.missing.cr_document', ['year' => $year]);
            }

            foreach ((array) $cr->reports as $report) {
                if ($this->reportNeedsNrc($report) && ! $this->hasDocument($row, EvaluationDocumentKind::CrNrc, $year)) {
                    $missing[] = (string) __('dpc.employee_details.missing.cr_nrc', ['year' => $year]);

                    break;
                }
            }
        }

        if ($anyPutUp && ($row->cr_channel_undertaking_at === null || ! $this->hasDocument($row, EvaluationDocumentKind::CrChannel, null))) {
            $missing[] = (string) __('dpc.employee_details.missing.cr_channel');
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function propertyMissing(DpcEvaluationRow $row): array
    {
        if ($row->propertyFlags->isEmpty()) {
            return [(string) __('dpc.employee_details.missing.property_none')];
        }

        $missing = [];
        $requireDocuments = (bool) config('dpc.employee_details.require_property_documents', true);

        foreach ($row->propertyFlags as $property) {
            /** @var DpcEvaluationPropertyFlag $property */
            $year = $property->year;

            if ($property->flag === null && $property->submitted_on === null) {
                $missing[] = (string) __('dpc.employee_details.missing.property_unanswered', ['year' => $year]);

                continue;
            }

            if ($property->submitted_on === null) {
                continue; // Recorded as not submitted — the engine judges it.
            }

            if ($property->next_increment_date === null) {
                $missing[] = (string) __('dpc.employee_details.missing.property_increment', ['year' => $year]);
            }

            if ($requireDocuments && ! $this->hasDocument($row, EvaluationDocumentKind::PropertyReturn, $year)) {
                $missing[] = (string) __('dpc.employee_details.missing.property_document', ['year' => $year]);
            }

            if ($property->isLate()) {
                if ($property->late_decision === null) {
                    $missing[] = (string) __('dpc.employee_details.missing.property_late_decision', ['year' => $year]);
                } elseif ($property->late_decision === DpcEvaluationPropertyFlag::LATE_ACCEPTED
                    && ($property->late_decision_remarks === null || ! $this->hasDocument($row, EvaluationDocumentKind::PropertyLateDecision, $year))) {
                    $missing[] = (string) __('dpc.employee_details.missing.property_late_document', ['year' => $year]);
                }
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function phMissing(DpcEvaluationRow $row): array
    {
        $outcome = $row->phOutcome();

        if ($outcome === PhReservationOutcome::NotRecorded) {
            return [(string) __('dpc.employee_details.missing.ph_unanswered')];
        }

        if ($outcome->requiresUndertaking() && ! $this->hasDocument($row, EvaluationDocumentKind::PhUndertaking, null)) {
            return [(string) __('dpc.employee_details.missing.ph_undertaking')];
        }

        return [];
    }

    private function hasDocument(DpcEvaluationRow $row, EvaluationDocumentKind $kind, ?int $year): bool
    {
        return $row->currentDocuments->contains(
            fn (DpcEvaluationDocument $document): bool => $document->kind === $kind && $document->year === $year,
        );
    }

    private function hasJustifiedGap(DpcEvaluationRow $row, int $year): bool
    {
        return $row->recordGaps->contains(
            fn (DpcEvaluationRecordGap $gap): bool => $gap->kind === DpcEvaluationRecordGap::KIND_CR
                && $gap->year === $year
                && $gap->hasDocument(),
        );
    }

    private function storeDocument(
        DpcEvaluationRow $row,
        EvaluationDocumentKind $kind,
        ?int $year,
        UploadedFile $file,
        bool $undertaking,
        ?string $remarks,
        User $actor,
    ): DpcEvaluationDocument {
        if ($kind->isYearly() && $year === null) {
            throw new WorkflowException(__('dpc.employee_details.documents.year_required'));
        }

        if (! $kind->isYearly()) {
            $year = null;
        }

        if ($kind->requiresUndertaking() && ! $undertaking) {
            throw new WorkflowException(__('dpc.employee_details.documents.undertaking_required'));
        }

        if ($kind === EvaluationDocumentKind::Other && trim((string) $remarks) === '') {
            throw new WorkflowException(__('dpc.employee_details.documents.remarks_required'));
        }

        if ($kind === EvaluationDocumentKind::PhUndertaking && ! $row->phOutcome()->requiresUndertaking()) {
            throw new WorkflowException(__('dpc.employee_details.ph.undertaking_not_applicable'));
        }

        // Hashed from the bytes that arrived, not re-read from the stored copy.
        $sha256 = (string) hash_file('sha256', (string) $file->getRealPath());

        // Page 4: the same IPR is sometimes uploaded for two years.
        if ($kind === EvaluationDocumentKind::PropertyReturn) {
            $sameFile = $row->currentDocuments()
                ->where('kind', $kind->value)
                ->where('year', '!=', $year)
                ->where('sha256', $sha256)
                ->value('year');

            if ($sameFile !== null) {
                throw new WorkflowException(__('dpc.employee_details.property.duplicate_file', ['year' => $sameFile]));
            }
        }

        return DB::transaction(function () use ($row, $kind, $year, $file, $undertaking, $remarks, $actor, $sha256): DpcEvaluationDocument {
            $disk = (string) config('dpc.documents.disk', 'local');
            $path = Storage::disk($disk)->putFile(sprintf('private/employee-documents/%d', $row->dpc_proposal_id), $file);

            if ($path === false) {
                throw new WorkflowException(__('dpc.employee_details.documents.store_failed'));
            }

            $document = DpcEvaluationDocument::query()->create([
                'dpc_proposal_id' => $row->dpc_proposal_id,
                'dpc_evaluation_row_id' => $row->id,
                'employee_number' => $row->employee_number,
                'kind' => $kind->value,
                'year' => $year,
                'undertaking_confirmed_at' => $undertaking ? now() : null,
                'remarks' => $this->blankToNull($remarks),
                'disk' => $disk,
                'path' => $path,
                // Display only; never used to build the stored path.
                'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'size_bytes' => (int) ($file->getSize() ?: 0),
                'sha256' => $sha256,
                'uploaded_by' => $actor->id,
                'uploaded_at' => now(),
            ]);

            if ($kind !== EvaluationDocumentKind::Other) {
                DpcEvaluationDocument::query()
                    ->where('dpc_evaluation_row_id', $row->id)
                    ->where('kind', $kind->value)
                    ->where(fn ($query) => $year === null ? $query->whereNull('year') : $query->where('year', $year))
                    ->whereNull('superseded_at')
                    ->whereKeyNot($document->id)
                    ->get()
                    ->each(fn (DpcEvaluationDocument $previous) => $previous->forceFill([
                        'superseded_at' => now(),
                        'superseded_by_id' => $document->id,
                    ])->save());
            }

            $this->log($row, 'document_uploaded', sprintf('%s uploaded%s', $kind->value, $year === null ? '' : " for {$year}"), [
                'kind' => $kind->value,
                'year' => $year,
                'document_uuid' => $document->uuid,
                'document_name' => $document->original_name,
                'document_sha256' => $sha256,
                'undertaking' => $undertaking,
            ], $actor);

            $row->unsetRelation('currentDocuments');

            return $document;
        });
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function normaliseReports(mixed $reports): array
    {
        $normalised = [];

        foreach ((array) $reports as $report) {
            if (! is_array($report)) {
                continue;
            }

            $entry = [];

            foreach (['reporter', 'reviewer', 'accepting'] as $role) {
                $entry["{$role}_designation"] = $this->blankToNull($report["{$role}_designation"] ?? null);
                $entry["{$role}_grade"] = $this->blankToNull($report["{$role}_grade"] ?? null);
            }

            if ($this->finalGrade($entry) === null) {
                continue; // An empty report block on the form.
            }

            $normalised[] = $entry;
        }

        $max = (int) config('dpc.employee_details.max_crs_per_year', 4);

        if (count($normalised) > $max) {
            throw new WorkflowException(__('dpc.employee_details.cr.too_many_reports', ['max' => $max]));
        }

        return $normalised;
    }

    private function assertEditable(DpcEvaluationRow $row): void
    {
        $proposal = $row->proposal;

        if (! $proposal->status->isEditable()) {
            throw new WorkflowException(__('dpc.employee_details.not_editable'));
        }
    }

    /**
     * A CR or IPR year must be a real past year: no later than the DPC year and no
     * earlier than the configured sanity bound. Not a business window — the engine's
     * settings decide which years count.
     */
    private function assertYear(DpcEvaluationRow $row, int $year): void
    {
        $proposal = $row->proposal;
        $latest = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);
        $earliest = (int) config('dpc.evaluation.min_year', 1950);

        if ($year < $earliest || $year > $latest) {
            throw new WorkflowException(__('dpc.employee_details.year_out_of_range', ['from' => $earliest, 'to' => $latest]));
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(DpcEvaluationRow $row, string $event, string $description, array $properties, User $actor): void
    {
        $this->timeline->record(
            "dpc_proposal.employee_{$event}",
            $row->proposal,
            sprintf('Officer %d (%s): %s', $row->sn, $row->name, $description),
            ['sn' => $row->sn, 'employee_number' => $row->employee_number] + $properties,
            $actor,
        );
    }
}
