<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Enums\PhReservationOutcome;
use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Dpc\Models\DpcEvaluationDocument;
use App\Domain\Dpc\Models\DpcEvaluationLoyaltyFlag;
use App\Domain\Dpc\Models\DpcEvaluationPropertyFlag;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\CrWindow;
use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Master\Models\IntegrityOption;
use App\Domain\Master\Services\PhReservationPolicy;
use App\Domain\Master\Services\PromotionCrRules;
use App\Domain\Master\Support\ReservationMode;
use App\Domain\Proceeding\Services\ChairmanSittingEdit;
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
        private readonly PromotionCrRules $promotionRules,
    ) {}

    // ------------------------------------------------------------------
    // A. Basic information
    // ------------------------------------------------------------------

    /**
     * The fields the simplified Seniority List does not carry, recorded by the
     * Authorized Officer. Name, date of birth, mobile number (2026-11-12), qualification (MoM of 12 November 2026), Employee ID
     * and seniority number come from the uploaded lists and are not editable here.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveBasic(DpcEvaluationRow $row, array $data, User $actor): void
    {
        $this->assertEditable($row);

        $row->forceFill([
            'reservation_category_id' => $data['reservation_category_id'] ?? null,
            // The posting date is the Seniority List's, read-only here (2026-11-14).
            'dob_order_number' => $this->blankToNull($data['dob_order_number'] ?? null),
            'dob_order_date' => $data['dob_order_date'] ?? null,
            'joining_order_number' => $this->blankToNull($data['joining_order_number'] ?? null),
            'joining_order_date' => $data['joining_order_date'] ?? null,
        ])->save();

        // Rule 4.4: the undertaking stands while it is ticked; unticked, it is withdrawn.
        $undertaking = (bool) ($data['service_record_undertaking'] ?? false);
        $row->forceFill([
            'service_record_undertaking_by' => $undertaking ? ($row->service_record_undertaking_by ?? $actor->id) : null,
            'service_record_undertaking_at' => $undertaking ? ($row->service_record_undertaking_at ?? now()) : null,
        ])->save();

        $this->log($row, 'basic_saved', 'Basic information saved', ['service_record_undertaking' => $undertaking], $actor);
    }

    // ------------------------------------------------------------------
    // C. Confidential Reports
    // ------------------------------------------------------------------

    /**
     * One CR year (MoM of 12 November 2026, point 7): was a CR put up; does it carry a
     * grading; and, if so, how many CRs were written that year — up to four — each
     * with the authorities whose remarks it carries and its grade, if it has one. The
     * year's grade is the one graded CR's, or for several the nearest grade at or
     * below their average. A CR missing an authority's remarks needs the year's NRC,
     * uploaded with it. The DPC year itself is not a CR year.
     *
     * A year without a grading, or with no CR put up, keeps no grade.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveCrYear(DpcEvaluationRow $row, int $year, array $data, User $actor, ?UploadedFile $nrc = null): DpcEvaluationCrGrade
    {
        $this->assertEditable($row);
        $this->assertYear($row, $year);

        $dpcYear = (int) ($row->proposal->dpc_year ?: $row->proposal->proposal_date->year);
        if ($year >= $dpcYear) {
            throw new WorkflowException(__('dpc.employee_details.cr.year_before_dpc_year', ['year' => $dpcYear - 1]));
        }

        // At most the required CRs (2026-11-14): a new year only inside the window,
        // which a year missing or without grading in the middle stretches back by one.
        if (! $row->crGrades()->where('year', $year)->exists() && ! in_array($year, $this->crYearWindow($row), true)) {
            throw new WorkflowException(__('dpc.employee_details.cr.year_outside_window', ['years' => implode(', ', $this->crYearWindow($row)) ?: '—']));
        }

        $putUp = (bool) $data['put_up'];
        $hasGrading = $putUp ? (bool) ($data['has_grading'] ?? false) : null;
        $reports = null;
        // Integrity (सत्यनिष्ठा) of a CR put up, chosen beside the grade (2026-11-14).
        $integrityId = $putUp && ! empty($data['integrity_option_id']) ? (int) $data['integrity_option_id'] : null;
        // The Final Departmental View, only where the answer asks for it (2026-11-16).
        $view = $integrityId !== null && IntegrityOption::query()->whereKey($integrityId)->value('needs_departmental_view')
            ? ($data['integrity_departmental_view'] ?? null)
            : null;

        if ($putUp && $hasGrading) {
            $reports = $this->reportsFrom($data);

            if ($reports === [] || array_filter($reports, fn (array $report): bool => $report['grade'] !== null) === []) {
                throw new WorkflowException(__('dpc.employee_details.cr.reports_required'));
            }

            $needsNrc = array_filter($reports, fn (array $report): bool => $this->reportNeedsNrc($report)) !== [];
            if ($needsNrc && $nrc === null && ! $this->hasDocument($row->loadMissing('currentDocuments'), EvaluationDocumentKind::CrNrc, $year)) {
                throw new WorkflowException(__('dpc.employee_details.cr.nrc_required'));
            }
        }

        $grade = $reports === null ? null : $this->yearGrade($reports, $dpcYear);

        if ($nrc !== null) {
            $this->storeDocument($row, EvaluationDocumentKind::CrNrc, $year, $nrc, false, null, $actor);
        }

        /** @var DpcEvaluationCrGrade $record */
        $record = $row->crGrades()->firstOrNew(['year' => $year]);
        $record->forceFill([
            'put_up' => $putUp,
            'has_grading' => $hasGrading,
            'reports' => $reports,
            'grade' => $grade,
            'integrity_option_id' => $integrityId,
            'integrity_departmental_view' => $view,
            // Posted in a tribal area this year (2026-11-16): the per-year tribal bonus
            // reads it; asked of a graded CR only, the one a bonus can attach to.
            // Answered for each CR (2026-11-18): the year is tribal when any of its CRs is.
            'tribal_area' => $this->yearTribal($putUp, (bool) $hasGrading, $reports, $data, $record),
        ])->save();

        // The engine reads the year's Integrity Certificate as held once an answer is
        // recorded; whether that answer is Fit is the Unfit ground's (2026-11-14).
        if ($integrityId !== null) {
            DpcEvaluationLoyaltyFlag::query()->updateOrCreate(['dpc_evaluation_row_id' => $row->id, 'year' => $year], ['flag' => true]);
            $row->unsetRelation('loyaltyFlags');
        }
        $row->unsetRelation('crGrades');

        $this->log($row, 'cr_year_saved', sprintf('CR %d recorded: %s', $year, $grade ?? ($putUp ? 'no grading' : 'not put up')), [
            'year' => $year,
            'put_up' => $putUp,
            'has_grading' => $hasGrading,
            'grade' => $grade,
            'authority' => $reports[0]['authority'] ?? null,
            'cr_count' => $reports === null ? null : count($reports),
            'integrity_option_id' => $integrityId,
            'integrity_departmental_view' => $view,
        ], $actor);

        return $record;
    }

    /**
     * The year's tribal answer: from its CRs where they carry one (2026-11-18) — any
     * of them in a tribal area — else the year-level answer of the earlier form, else
     * what was recorded. Only for a graded CR, the one a bonus can attach to.
     *
     * @param  list<array<string, mixed>>|null  $reports
     * @param  array<string, mixed>  $data
     */
    private function yearTribal(bool $putUp, bool $hasGrading, ?array $reports, array $data, DpcEvaluationCrGrade $record): ?bool
    {
        if (! $putUp || ! $hasGrading) {
            return null;
        }

        $answers = array_values(array_filter(array_map(fn (array $report): ?bool => $report['tribal_area'] ?? null, $reports ?? []), fn (?bool $answer): bool => $answer !== null));
        if ($answers !== []) {
            return in_array(true, $answers, true);
        }

        if (array_key_exists('tribal_area', $data) && $data['tribal_area'] !== null && $data['tribal_area'] !== '') {
            return (bool) $data['tribal_area'];
        }

        return $record->tribal_area;
    }

    /**
     * The CRs of a year as posted: `reports[]` (each with `authorities` and `grade`)
     * up to `cr_count`, or the single CR of the earlier form (`authorities`, `grade`).
     *
     * @param  array<string, mixed>  $data
     * @return list<array{authorities: list<string>, authority: string|null, grade: string|null, tribal_area?: bool}>
     */
    private function reportsFrom(array $data): array
    {
        $max = (int) config('dpc.employee_details.max_crs_per_year', 4);
        $posted = isset($data['reports']) && is_array($data['reports'])
            ? array_slice(array_values($data['reports']), 0, max(1, min($max, (int) ($data['cr_count'] ?? count($data['reports'])))))
            : [['authorities' => $data['authorities'] ?? [], 'grade' => $data['grade'] ?? null]];

        $reports = [];
        foreach ($posted as $report) {
            $authorities = array_values(array_intersect(self::CR_AUTHORITIES, (array) ($report['authorities'] ?? [])));
            $grade = $this->blankToNull($report['grade'] ?? null);

            if ($authorities === [] && $grade === null) {
                continue;
            }

            if ($authorities === []) {
                throw new WorkflowException(__('dpc.employee_details.cr.reports_required'));
            }

            // Posted in a tribal area for this CR (2026-11-18): each CR of a year is
            // upgraded by the tribal rule on its own.
            $tribal = $report['tribal_area'] ?? null;

            $reports[] = ['authorities' => $authorities, 'authority' => end($authorities), 'grade' => $grade]
                + ($tribal === null || $tribal === '' ? [] : ['tribal_area' => (bool) $tribal]);
        }

        if (count($reports) > $max) {
            throw new WorkflowException(__('dpc.employee_details.cr.too_many_crs', ['max' => $max]));
        }

        return $reports;
    }

    /**
     * The CR document uploaded with its year (2026-11-14): the CR for a graded year,
     * the CR without grading for one without — chosen from the year just saved, so the
     * document always matches its answer. A year whose CR was not put up has no CR to
     * upload; the file is ignored. The same checks as the separate upload apply.
     */
    public function uploadCrWithYear(DpcEvaluationRow $row, int $year, UploadedFile $file, bool $undertaking, bool $verified, User $actor, ?int $part = null): ?DpcEvaluationDocument
    {
        $record = $row->crGrades()->where('year', $year)->first();

        if ($record === null || $record->put_up !== true) {
            return null;
        }

        return $this->upload($row, EvaluationDocumentKind::CrReport, $year, $file, $undertaking, null, $actor, $verified, $part);
    }

    /**
     * The CR years asked for (business decisions of 2026-11-14): the required number
     * (five), counted back from the year before the DPC year. A year recorded as
     * missing, or put up without a grading, does not count, so the window stretches
     * one year further back for each — except the year just before the DPC year,
     * which stays in the window (four CRs then suffice). Never before the officer
     * joined service.
     *
     * @return list<int> latest first
     */
    public function crYearWindow(DpcEvaluationRow $row): array
    {
        $proposal = $row->proposal;
        $last = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year) - 1;
        // Not before joining — nor, where the rule says so, before the year recorded
        // "appointed in this year" (2026-11-18).
        $from = max((int) config('dpc.evaluation.min_year', 1950), $row->serviceFloorYear() ?? ($last - 20));
        $notCounted = $row->crGrades()->get()
            ->filter(fn ($record): bool => $record->put_up === false || ($record->put_up === true && $record->has_grading === false))
            ->pluck('year')->map(fn ($year): int => (int) $year)->values()->all();
        // The promotion rule's CR count (2026-11-18: the rule decides, no fixed five);
        // five only for a DPC without a rule.
        $slots = (int) ($this->promotionRules->ruleForProposal($proposal)->cr_years ?? config('dpc.merit.required_cr_count', 5));

        return CrWindow::of($last, $slots, $notCounted, $from)->years;
    }

    /**
     * The Property Return years: as many continuous years before the DPC year as the
     * promotion rule's CR years (2026-11-18), or five (2021 to 2025 for a 2026 DPC)
     * for a DPC without a rule.
     *
     * @return list<int> latest first
     */
    public function propertyYearWindow(DpcEvaluationRow $row): array
    {
        $proposal = $row->proposal;
        $last = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year) - 1;
        // The promotion rule's Property Declaration years (2026-11-18: the rule decides,
        // no fixed five); five only for a DPC without a rule. None before the year
        // recorded "appointed in this year" where the rule says so.
        // As many years as the rule's CR years (2026-11-18: the rule's IPR years are its
        // CR years); five only for a DPC without a rule.
        $rule = $this->promotionRules->ruleForProposal($proposal);
        $count = max(1, $rule === null ? (int) config('dpc.merit.property_return_years', 5) : (int) $rule->property_years);
        $first = $last - $count + 1;

        if ($row->appointedYearIsFit()) {
            $first = max($first, (int) $row->appointedYear());
        }

        return range($last, min($last, $first));
    }

    /** The authorities a CR can carry remarks of, lowest first. */
    public const CR_AUTHORITIES = ['reporter', 'reviewer', 'accepting'];

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
     * The year's grade (MoM of 12 November 2026): the graded CR's grade where only one
     * is graded; where several are, the average of their points, taken down to the
     * nearest grade at or below it — never rounded up.
     *
     * @param  list<array<string, mixed>>  $reports
     */
    public function yearGrade(array $reports, ?int $dpcYear = null): string
    {
        $grades = array_values(array_filter(array_map(fn (array $report): ?string => $this->finalGrade($report), $reports)));

        if ($grades === []) {
            throw new WorkflowException(__('dpc.employee_details.cr.reports_required'));
        }

        if (count($grades) === 1) {
            return $grades[0];
        }

        $grade = DpcEvaluationCrGrade::yearGradeOf($grades, app(PromotionCrRules::class)->pointsForYear($dpcYear ?? (int) now()->year));

        if ($grade === null) {
            throw new WorkflowException(__('dpc.employee_details.cr.grade_without_points'));
        }

        return $grade;
    }

    /**
     * Page 3: the final remark is the accepting officer's, else the reviewer's, else
     * the reporter's.
     *
     * @param  array<string, mixed>  $report
     */
    public function finalGrade(array $report): ?string
    {
        return DpcEvaluationCrGrade::finalGradeOf($report);
    }

    /**
     * Page 3: an NRC is compulsory when any of the three remarks is missing — on the
     * one-CR form, an authority left unticked.
     *
     * @param  array<string, mixed>  $report
     */
    public function reportNeedsNrc(array $report): bool
    {
        if (isset($report['authorities'])) {
            return array_diff(self::CR_AUTHORITIES, (array) $report['authorities']) !== [];
        }

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

        // Only the five years before the DPC year (2026-11-14); a year already recorded keeps its Edit.
        if (! $row->propertyFlags()->where('year', $year)->exists() && ! in_array($year, $this->propertyYearWindow($row), true)) {
            throw new WorkflowException(__('dpc.employee_details.property.year_outside_window', ['years' => implode(', ', array_reverse($this->propertyYearWindow($row)))]));
        }

        $submitted = (bool) $data['submitted'];
        $submittedOn = $submitted ? Carbon::parse((string) $data['submitted_on'])->startOfDay() : null;
        $nextIncrement = $submitted && ($data['next_increment_date'] ?? null) !== null
            ? Carbon::parse((string) $data['next_increment_date'])->startOfDay()
            : null;

        $late = $submittedOn !== null && $submittedOn->gt(DpcEvaluationPropertyFlag::dueDateFor($year));
        $decision = $late ? ($data['late_decision'] ?? null) : null;

        // The date of next increment is 1 January or 1 July of the next year
        // (2026-11-16, SaveEmployeePropertyYearRequest), so two years can no longer
        // share one: the check that refused a shared date is gone.

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

        $this->log($row, 'property_year_saved', sprintf('Property File %d recorded: %s', $year, $submitted ? 'submitted' : 'not submitted'), [
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

        $this->log($row, 'property_year_removed', "Property File {$year} removed", ['year' => $year], $actor);
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
            'ph_type_id' => $isPh && ! empty($data['ph_type_id']) ? (int) $data['ph_type_id'] : null,
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
            'ph_type_id' => $row->ph_type_id,
            'previously_availed' => $previously,
            'claims_reservation' => $claims,
            'outcome' => $outcome->value,
        ], $actor);

        return $outcome;
    }

    /**
     * Rule 2.3: whether the officer is on deputation without a sanctioned setup; rule
     * 4.4: whether the officer refused promotion to this post, and in which year. The
     * year is kept only for a refusal.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDeclarations(DpcEvaluationRow $row, array $data, User $actor): void
    {
        $this->assertEditable($row);

        $refused = (bool) $data['refused_promotion'];

        $row->forceFill([
            'unsanctioned_deputation' => (bool) $data['unsanctioned_deputation'],
            'refused_promotion' => $refused,
            'refused_promotion_year' => $refused ? (int) $data['refused_promotion_year'] : null,
            'declarations_recorded_by' => $actor->id,
            'declarations_recorded_at' => now(),
        ])->save();

        $this->log($row, 'declarations_recorded', 'Deputation and promotion-refusal declarations recorded', [
            'unsanctioned_deputation' => $row->unsanctioned_deputation,
            'refused_promotion' => $refused,
            'refused_promotion_year' => $row->refused_promotion_year,
        ], $actor);
    }

    /**
     * Rule 2.2: whether a departmental enquiry, charge-sheet, show-cause notice or
     * suspension is pending against the officer; if so the reason (from the Sealed
     * Cover Reason master), its date and remarks. Nothing pending clears them.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveVigilance(DpcEvaluationRow $row, array $data, User $actor): void
    {
        $this->assertEditable($row);

        // The "matter pending" question is no longer asked (2026-11-18): the first
        // question's Yes is the sealed cover. A form that still sends it is honoured;
        // one that does not leaves what was recorded untouched.
        $asked = array_key_exists('vigilance_pending', $data) && $data['vigilance_pending'] !== null;
        $pending = $asked ? (bool) $data['vigilance_pending'] : $row->vigilance_pending === true;

        $row->forceFill([
            // A charge-sheet with the inquiry / court case ongoing: sealed cover (2026-11-18);
            // a punishment in effect in the DPC's period: Unfit (2026-11-14).
            'enquiry_ongoing' => array_key_exists('enquiry_ongoing', $data) ? (bool) $data['enquiry_ongoing'] : $row->enquiry_ongoing,
            'punishment_effective' => array_key_exists('punishment_effective', $data) ? (bool) $data['punishment_effective'] : $row->punishment_effective,
            'vigilance_recorded_by' => $actor->id,
            'vigilance_recorded_at' => now(),
        ] + ($asked ? [
            'vigilance_pending' => $pending,
            'sealed_cover_reason_id' => $pending ? (int) $data['sealed_cover_reason_id'] : null,
            'vigilance_date' => $pending ? $data['vigilance_date'] : null,
            'vigilance_remarks' => $pending ? $this->blankToNull($data['vigilance_remarks'] ?? null) : null,
        ] : []))->save();

        $row->unsetRelation('sealedCoverReason');

        $this->log($row, 'vigilance_recorded', $pending
            ? 'Departmental matter pending: '.($row->sealedCoverReason->code ?? '—')
            : 'No departmental matter pending', [
                'pending' => $pending,
                'enquiry_ongoing' => $row->enquiry_ongoing,
                'punishment_effective' => $row->punishment_effective,
                'reason' => $row->sealedCoverReason?->code,
                'sealed_cover' => $row->isUnderSealedCover(),
            ], $actor);
    }

    /**
     * The period the two enquiry questions ask about: from 1 January of the DPC year
     * to the proposal's date — for a 2026 DPC, from 1 January 2026 (2026-11-16; it
     * began a year earlier from 2026-11-14).
     *
     * @return array{from: Carbon, to: Carbon}
     */
    public function enquiryPeriod(DpcProposal $proposal): array
    {
        $year = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);

        return ['from' => Carbon::create($year, 1, 1)->startOfDay(), 'to' => ($proposal->proposal_date ?? now())->copy()->startOfDay()];
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
        bool $verified = false,
        ?int $part = null,
    ): DpcEvaluationDocument {
        $this->assertEditable($row);

        // Rule 4.4: the AO's verification of the document against its original is the first of two.
        if (! $verified) {
            throw new WorkflowException(__('dpc.employee_details.verification.ao_required'));
        }

        if ($kind === EvaluationDocumentKind::CrChannel) {
            // The document is the evidence for the undertaking; neither stands alone.
            if (! $undertaking) {
                throw new WorkflowException(__('dpc.employee_details.documents.undertaking_required'));
            }

            return $this->confirmChannel($row, $file, $actor);
        }

        // A CR's kind follows the year's answer, not the uploader's choice (2026-11-14):
        // a graded CR is the CR, one without grading the CR without grading.
        if (in_array($kind, [EvaluationDocumentKind::CrReport, EvaluationDocumentKind::CrWithoutGrading], true) && $year !== null) {
            $record = $row->crGrades()->where('year', $year)->first();

            if ($record !== null) {
                if ($record->put_up !== true) {
                    throw new WorkflowException(__('dpc.employee_details.cr.no_cr_to_upload', ['year' => $year]));
                }

                $kind = $record->has_grading ? EvaluationDocumentKind::CrReport : EvaluationDocumentKind::CrWithoutGrading;
            }
        }

        return $this->storeDocument($row, $kind, $year, $file, $undertaking, $remarks, $actor, $part);
    }

    /**
     * An NRC certifies the remarks missing from a CR, so it comes with the CR
     * (2026-11-14): a year whose NRC is uploaded needs its CR on file too.
     */
    public function assertNrcHasItsCr(DpcEvaluationRow $row, int $year): void
    {
        $row->unsetRelation('currentDocuments');

        if (! $this->hasDocument($row, EvaluationDocumentKind::CrReport, $year)) {
            throw new WorkflowException(__('dpc.employee_details.cr.nrc_needs_cr'));
        }
    }

    /**
     * Rule 4.4's second verification: the Administrative Head's, on a current document
     * of a submitted proposal. Verifying again changes nothing.
     */
    public function verifyByHead(DpcEvaluationDocument $document, User $actor): void
    {
        if ($document->ah_verified_at !== null || ! $document->isCurrent()) {
            return;
        }

        $document->forceFill(['ah_verified_by' => $actor->id, 'ah_verified_at' => now()])->save();

        $row = $document->row;

        if ($row !== null) {
            $this->log($row, 'document_verified_by_head', 'Document verified by the Administrative Head: '.$document->kind->value, [
                'document' => $document->uuid,
            ], $actor);
        }
    }

    /**
     * Rule 2.1: the Administrative Head verifies a PH claim of a submitted proposal —
     * verified, the officer competes for a PH seat; not verified, no benefit.
     */
    public function verifyPhClaim(DpcEvaluationRow $row, bool $verified, ?string $remarks, User $actor): void
    {
        if (! $row->phOutcome()->isClaim()) {
            throw new WorkflowException(__('dpc.employee_details.ph.not_a_claim'));
        }

        $row->forceFill([
            'ph_verification_status' => $verified ? 'verified' : 'not_verified',
            'ph_final_decision' => $verified ? 'benefit' : 'no_benefit',
            'ph_verified_by' => $actor->id,
            'ph_verified_at' => now(),
            'ph_verification_remarks' => $this->blankToNull($remarks),
        ])->save();

        $this->log($row, 'ph_claim_verified', 'PH claim '.($verified ? 'verified' : 'not verified').' by the Administrative Head', [
            'verified' => $verified,
            'remarks' => $row->ph_verification_remarks,
        ], $actor);
    }

    /** Rule 2.1: PH claims of a proposal still waiting for the Administrative Head. */
    public function phClaimsAwaitingHead(DpcProposal $proposal): int
    {
        return $proposal->evaluationRows()
            ->where('is_ph', true)
            ->where('ph_previously_availed', false)
            ->where('ph_claims_reservation', true)
            ->where('ph_verification_status', 'pending')
            ->count();
    }

    /**
     * The current documents of a proposal still waiting for the Administrative Head's
     * verification (rule 4.4).
     */
    public function documentsAwaitingHead(DpcProposal $proposal): int
    {
        return DpcEvaluationDocument::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->current()
            ->whereNull('ah_verified_at')
            ->count();
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
    public function status(DpcEvaluationRow $row, ?bool $phApplies = null): array
    {
        $phApplies ??= $row->proposal === null || $this->phApplies($row->proposal);

        $row->loadMissing(['crGrades.integrityOption', 'propertyFlags', 'recordGaps.reason', 'currentDocuments', 'sealedCoverReason']);

        $sections = [
            'basic' => $this->basicMissing($row),
            'cr' => $this->crMissing($row),
            'property' => $this->propertyMissing($row),
            'ph' => $phApplies ? $this->phMissing($row) : [],
            'vigilance' => $this->vigilanceMissing($row),
            'declarations' => $this->declarationsMissing($row),
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
        $proposal->loadMissing(['promotionFromPost', 'promotionToPost']);
        // Read once for the whole list (MoM of 12 November 2026).
        $phApplies = $this->phApplies($proposal);

        return $proposal->evaluationRows()
            ->with(['crGrades.integrityOption', 'propertyFlags', 'recordGaps.reason', 'currentDocuments', 'reservationCategory', 'sealedCoverReason'])
            ->get()
            // The proposal is the same for every row: its promotion rule is read once.
            ->each(fn (DpcEvaluationRow $row) => $row->setRelation('proposal', $proposal))
            ->map(fn (DpcEvaluationRow $row): array => ['row' => $row, 'status' => $this->status($row, $phApplies)])
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
        // The stored vacancies once written; before that, the promotion vacancy the
        // Vacancy Management record gives (MoM of 12 November 2026).
        $vacancies = (int) $proposal->vacancies()->sum('count');
        if ($vacancies === 0) {
            $vacancies = max(0, (int) (app(VacancyCalculationService::class)->breakdown($proposal)->promotionVacancy ?? 0));
        }

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

    /**
     * Whether PH reservation applies to this DPC: its vacancies give at least one
     * PH seat (MoM of 12 November 2026). Where none does, the PH Reservation section
     * is not shown and not asked.
     */
    public function phApplies(DpcProposal $proposal): bool
    {
        return $this->phQuota($proposal)['posts'] > 0;
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

        // Rule 4.4: the orders behind the date of birth and of joining, and the undertaking.
        if ($row->dob_order_number === null || $row->dob_order_date === null) {
            $missing[] = (string) __('dpc.employee_details.missing.dob_order');
        }

        if ($row->joining_order_number === null || $row->joining_order_date === null) {
            $missing[] = (string) __('dpc.employee_details.missing.joining_order');
        }

        if ($row->service_record_undertaking_at === null) {
            $missing[] = (string) __('dpc.employee_details.missing.service_record_undertaking');
        }

        return $missing;
    }

    /**
     * The CR years the Class Master promotion rule makes mandatory for this officer's
     * proposal; none when no rule is in force (the rule check then says so).
     *
     * @return list<int>
     */
    public function ruleCrYears(DpcEvaluationRow $row): array
    {
        $proposal = $row->proposal;
        $rule = $proposal === null ? null : $this->promotionRules->ruleForProposal($proposal);

        if ($rule === null) {
            return [];
        }

        // The officer's own CR window, as the rule check and the calculation take it (2026-11-16).
        $dpcYear = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);

        // Only what the window reads, from the CR years already loaded for the screen.
        $officer = new OfficerRecord(
            name: (string) $row->name,
            crGrades: [],
            crNotCountedYears: $row->crGrades
                ->filter(fn ($record): bool => $record->put_up === false || ($record->put_up === true && $record->has_grading === false))
                ->map(fn ($record): int => (int) $record->year)->values()->all(),
            firstServiceYear: $row->serviceFloorYear(),
        );

        return RuleSet::forPromotionRule(RuleSet::fromConfig(), $rule, [], $dpcYear, '')->mandatoryCrYearsFor($officer);
    }

    /**
     * The years whose Property Return the Class Master promotion rule makes
     * mandatory (2026-11-05).
     *
     * @return list<int>
     */
    public function rulePropertyYears(DpcEvaluationRow $row): array
    {
        $proposal = $row->proposal;
        $rule = $proposal === null ? null : $this->promotionRules->ruleForProposal($proposal);

        // Not a year before the officer's appointment, where the rule says so (2026-11-18).
        return $rule === null ? [] : array_values(array_filter(
            $this->promotionRules->mandatoryPropertyYears($rule, (int) ($proposal->dpc_year ?: $proposal->proposal_date->year)),
            fn (int $year): bool => ! $row->isBeforeAppointment($year),
        ));
    }

    /**
     * @return list<string>
     */
    private function crMissing(DpcEvaluationRow $row): array
    {
        $requireDocuments = (bool) config('dpc.employee_details.require_cr_documents', true);

        // Step 3: every CR year the Class Master promotion rule makes mandatory is
        // answered; a year not put up then needs its rule-3.2 reason as below.
        $recorded = $row->crGrades->pluck('year')->all();
        $ruleMissing = array_values(array_map(
            static fn (int $year): string => (string) __('dpc.employee_details.missing.cr_rule_year', ['year' => $year]),
            array_diff($this->ruleCrYears($row), $recorded),
        ));

        if ($row->crGrades->isEmpty()) {
            return $ruleMissing !== [] ? $ruleMissing : [(string) __('dpc.employee_details.missing.cr_none')];
        }

        $missing = $ruleMissing;

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

            // Integrity, beside the grade (2026-11-14).
            if ($cr->integrity_option_id === null) {
                $missing[] = (string) __('dpc.employee_details.missing.cr_integrity', ['year' => $year]);
            } elseif ($cr->integrityOption?->needs_departmental_view) {
                // The Final Departmental View and its document (2026-11-16).
                if ($cr->integrity_departmental_view === null) {
                    $missing[] = (string) __('dpc.employee_details.missing.cr_integrity_view', ['year' => $year]);
                }
                if (! $this->hasDocument($row, EvaluationDocumentKind::IntegrityDecision, $year)) {
                    $missing[] = (string) __('dpc.employee_details.missing.cr_integrity_document', ['year' => $year]);
                }
            }

            // A CR without grading: uploaded, not counted (2026-11-16).
            if ($cr->has_grading === false) {
                if (! $this->hasDocument($row, EvaluationDocumentKind::CrWithoutGrading, $year)) {
                    $missing[] = (string) __('dpc.employee_details.missing.cr_without_grading_document', ['year' => $year]);
                }

                continue;
            }

            // Each CR of the year uploaded on its own (2026-11-16).
            if ($requireDocuments) {
                $crs = max(1, count((array) $cr->reports));

                foreach (range(1, $crs) as $number) {
                    if (! $this->hasCrDocument($row, $year, $number)) {
                        $missing[] = $crs === 1
                            ? (string) __('dpc.employee_details.missing.cr_document', ['year' => $year])
                            : (string) __('dpc.employee_details.missing.cr_document_numbered', ['year' => $year, 'number' => $number]);
                    }
                }
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
        // Every year the promotion rule makes mandatory is answered (2026-11-05), and
        // every one of the five years before the DPC year (2026-11-14).
        $ruleYears = $this->rulePropertyYears($row);
        $recordedYears = $row->propertyFlags->pluck('year')->all();
        $ruleMissing = array_values(array_map(
            static fn (int $year): string => (string) __('dpc.employee_details.missing.property_rule_year', ['year' => $year]),
            array_diff($ruleYears, $recordedYears),
        ));
        $windowMissing = $row->proposal === null ? [] : array_diff(array_reverse($this->propertyYearWindow($row)), $recordedYears, $ruleYears);
        foreach ($windowMissing as $year) {
            $ruleMissing[] = (string) __('dpc.employee_details.missing.property_window_year', ['year' => $year]);
        }

        if ($row->propertyFlags->isEmpty()) {
            return $ruleMissing !== [] ? $ruleMissing : [(string) __('dpc.employee_details.missing.property_none')];
        }

        $missing = $ruleMissing;
        $requireDocuments = (bool) config('dpc.employee_details.require_property_documents', true);

        foreach ($row->propertyFlags as $property) {
            /** @var DpcEvaluationPropertyFlag $property */
            $year = $property->year;

            if ($property->flag === null && $property->submitted_on === null) {
                $missing[] = (string) __('dpc.employee_details.missing.property_unanswered', ['year' => $year]);

                continue;
            }

            if ($property->submitted_on === null) {
                // Missing in a mandatory year: its reason and document (rule 3.2,
                // 2026-11-05). An optional year needs nothing.
                if (in_array($year, $ruleYears, true) && ! $this->hasJustifiedGap($row, $year, DpcEvaluationRecordGap::KIND_PROPERTY)) {
                    $missing[] = (string) __('dpc.employee_details.missing.property_reason', ['year' => $year]);
                }

                continue;
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
    private function declarationsMissing(DpcEvaluationRow $row): array
    {
        if ($row->unsanctioned_deputation === null) {
            return [(string) __('dpc.employee_details.missing.declarations_unanswered')];
        }

        $missing = [];

        if ($row->unsanctioned_deputation && ! $this->hasDocument($row, EvaluationDocumentKind::DeputationOrder, null)) {
            $missing[] = (string) __('dpc.employee_details.missing.deputation_order');
        }

        if ($row->refused_promotion) {
            if (! $this->hasDocument($row, EvaluationDocumentKind::RefusalLetter, null)) {
                $missing[] = (string) __('dpc.employee_details.missing.refusal_letter');
            }

            if (! $this->hasDocument($row, EvaluationDocumentKind::RefusalDpcOrder, null)) {
                $missing[] = (string) __('dpc.employee_details.missing.refusal_dpc_order');
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function vigilanceMissing(DpcEvaluationRow $row): array
    {
        // The two questions of 2026-11-14; a Yes with its document, a No with none (2026-11-16).
        $missing = [];
        foreach (['enquiry_ongoing' => EvaluationDocumentKind::EnquiryDocument, 'punishment_effective' => EvaluationDocumentKind::PunishmentDocument] as $field => $kind) {
            if ($row->{$field} === null) {
                $missing[] = (string) __("dpc.employee_details.missing.{$field}");
            } elseif ($row->{$field} === true && ! $this->hasDocument($row, $kind, null)) {
                $missing[] = (string) __("dpc.employee_details.missing.{$field}_document");
            }
        }

        return [...$missing, ...$this->sealedCoverMissing($row)];
    }

    /**
     * @return list<string>
     */
    private function sealedCoverMissing(DpcEvaluationRow $row): array
    {
        // No longer asked (2026-11-18); only a "matter pending" recorded before needs its details.
        if ($row->vigilance_pending !== true) {
            return [];
        }

        $reason = $row->sealedCoverReason;

        if ($reason === null) {
            return [(string) __('dpc.employee_details.missing.vigilance_reason')];
        }

        if ($reason->requires_document && ! $this->hasDocument($row, EvaluationDocumentKind::VigilanceOrder, null)) {
            return [(string) __('dpc.employee_details.missing.vigilance_order')];
        }

        return [];
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

    private function hasCrDocument(DpcEvaluationRow $row, int $year, int $number): bool
    {
        return $row->currentDocuments->contains(
            fn (DpcEvaluationDocument $document): bool => $document->kind === EvaluationDocumentKind::CrReport
                && $document->year === $year
                && $document->crNumber() === $number,
        );
    }

    private function hasJustifiedGap(DpcEvaluationRow $row, int $year, string $kind = DpcEvaluationRecordGap::KIND_CR): bool
    {
        return $row->recordGaps->contains(
            fn (DpcEvaluationRecordGap $gap): bool => $gap->kind === $kind
                && $gap->year === $year
                && $gap->isJustified(),
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
        ?int $part = null,
    ): DpcEvaluationDocument {
        // Only a graded CR is numbered within its year (2026-11-16).
        $part = $kind === EvaluationDocumentKind::CrReport ? max(1, (int) ($part ?? 1)) : null;

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

        if ($kind === EvaluationDocumentKind::VigilanceOrder && $row->vigilance_pending !== true) {
            throw new WorkflowException(__('dpc.employee_details.vigilance.order_not_applicable'));
        }

        if ($kind === EvaluationDocumentKind::DeputationOrder && $row->unsanctioned_deputation !== true) {
            throw new WorkflowException(__('dpc.employee_details.declarations.document_not_applicable'));
        }

        if (in_array($kind, [EvaluationDocumentKind::RefusalLetter, EvaluationDocumentKind::RefusalDpcOrder], true) && $row->refused_promotion !== true) {
            throw new WorkflowException(__('dpc.employee_details.declarations.document_not_applicable'));
        }

        if ($kind === EvaluationDocumentKind::PhUndertaking && ! $row->phOutcome()->requiresUndertaking()) {
            throw new WorkflowException(__('dpc.employee_details.ph.undertaking_not_applicable'));
        }

        // Hashed from the bytes that arrived, not re-read from the stored copy.
        $sha256 = (string) hash_file('sha256', (string) $file->getRealPath());

        // The same file may be uploaded for more than one year (2026-11-16): the
        // check that refused it is gone.

        return DB::transaction(function () use ($row, $kind, $year, $file, $undertaking, $remarks, $actor, $sha256, $part): DpcEvaluationDocument {
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
                'part' => $part,
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
                // The first verification (rule 4.4): the AO's, made on uploading.
                'ao_verified_by' => $actor->id,
                'ao_verified_at' => now(),
            ]);

            if ($kind !== EvaluationDocumentKind::Other) {
                DpcEvaluationDocument::query()
                    ->where('dpc_evaluation_row_id', $row->id)
                    ->where('kind', $kind->value)
                    ->where(fn ($query) => $year === null ? $query->whereNull('year') : $query->where('year', $year))
                    // A CR replaces only the same CR of the year; an older upload is the first.
                    ->when($part !== null, fn ($query) => $query->where(fn ($inner) => $part === 1
                        ? $inner->whereNull('part')->orWhere('part', 1)
                        : $inner->where('part', $part)))
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

    private function assertEditable(DpcEvaluationRow $row): void
    {
        $proposal = $row->proposal;

        // The DPC Chairman's own edits at the sitting (2026-11-18): who may is the
        // policy's to decide (DpcProposalPolicy::editAtSitting); this is the state.
        if (! $proposal->status->isEditable() && ! app(ChairmanSittingEdit::class)->windowOpen($proposal)) {
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
