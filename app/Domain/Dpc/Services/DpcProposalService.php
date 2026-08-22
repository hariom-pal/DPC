<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\SignatureService;
use App\Enums\Feature;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DPC Proposal workflow (PPT S12–S25) — M4 Phase 1–5 scope: post selection
 * (S14), committee (S15), working officers + vacancy breakdown (S16),
 * seniority list (S17), evaluation/tabulation chart (S18), and the shared
 * workflow (submit/approve/return/cancel). A single `approve`
 * transition covers AH review of every section (architect decision,
 * 2026-08-13) — one signature, one transition, one timeline entry, one
 * audit event, produced by the existing WorkflowEngine / SignatureService
 * exactly as in M3, with no duplication.
 *
 * Each transition re-fetches the row with `lockForUpdate()` inside its
 * transaction before reading status, closing the narrow double-submit /
 * double-approve race that exists at the Service layer in M3's equivalent
 * (flagged in the M4 design review as a hardening to carry forward).
 */
class DpcProposalService
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly SignatureService $signatures,
        private readonly DpcIdGeneratorService $dpcIds,
        private readonly ActivityTimelineService $timeline,
        private readonly DpcProposalCompletenessService $completeness,
        private readonly EvaluationCandidateService $candidates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(Department $department, User $actor, array $data): DpcProposal
    {
        $proposal = new DpcProposal([
            'department_id' => $department->id,
            'dpc_type_id' => $data['dpc_type_id'],
            'post_to_cadre_id' => $data['post_to_cadre_id'],
            'post_from_cadre_id' => $data['post_from_cadre_id'],
            'legal_stay' => $data['legal_stay'] ?? false,
            'proposal_date' => $data['proposal_date'],
            'status' => DpcProposalStatus::Draft->value,
            'prepared_by' => $actor->id,
        ]);
        $proposal->save();

        return $proposal;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(DpcProposal $proposal, array $data): DpcProposal
    {
        $this->assertEditable($proposal);

        $proposal->fill([
            'dpc_type_id' => $data['dpc_type_id'],
            'post_to_cadre_id' => $data['post_to_cadre_id'],
            'post_from_cadre_id' => $data['post_from_cadre_id'],
            'legal_stay' => $data['legal_stay'] ?? false,
            'proposal_date' => $data['proposal_date'],
        ])->save();

        return $proposal;
    }

    /**
     * DPC Committee (PPT S15) — M4 Phase 2. Members referencing an
     * existing User are denormalized server-side from that User's own
     * record (name/designation/mobile/email), never trusted from the
     * client, so a submitted user_id can't be paired with a spoofed
     * display name. External members use the client-submitted fields
     * as-is. Replaces the full member list per save (bounded by the
     * committee-configuration max, so individual `create()` calls — not
     * a bulk insert — are used deliberately: each one must fire through
     * Eloquent so it's audited (architect directive: every committee
     * change generates an Audit Entry).
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    public function saveCommittee(DpcProposal $proposal, array $members, User $actor): DpcProposal
    {
        $this->assertEditable($proposal);

        DB::transaction(function () use ($proposal, $members): void {
            $proposal->committeeMembers()->delete();

            $userIds = array_values(array_filter(array_column($members, 'user_id')));
            $users = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->get()->keyBy('id');

            $order = 0;
            foreach ($members as $memberData) {
                $userId = $memberData['user_id'] ?? null;
                $user = $userId !== null ? $users->get((int) $userId) : null;

                if ($user !== null) {
                    $name = $user->name;
                    $designation = $user->designation?->name;
                    $mobile = $user->mobile;
                    $email = $user->email;
                } else {
                    $name = (string) $memberData['name'];
                    $designation = $memberData['designation'] ?? null;
                    $mobile = $memberData['mobile'] ?? null;
                    $email = $memberData['email'] ?? null;
                }

                $proposal->committeeMembers()->create([
                    'user_id' => $user?->id,
                    'name' => $name,
                    'designation' => $designation,
                    'mobile' => $mobile,
                    'email' => $email,
                    'capacity' => $memberData['capacity'],
                    'sort_order' => $order++,
                ]);
            }
        });

        $this->timeline->record(
            'dpc_proposal.committee_updated',
            $proposal,
            'DPC committee updated ('.count($members).' members)',
            ['member_count' => count($members)],
            $actor,
        );

        return $proposal->fresh(['committeeMembers.user']) ?? $proposal;
    }

    /**
     * Vacancy breakdown (PPT S16) — M4 Phase 3. Strictly row-based: one row
     * per (vacancy type, reservation category) pair (architect directive —
     * never separate columns per type). `sanctioned_post_count` lives on
     * the proposal itself; it's captured on this same screen.
     *
     * @param  array<int, array<string, mixed>>  $vacancies
     */
    public function saveVacancies(DpcProposal $proposal, int $sanctionedPostCount, array $vacancies): DpcProposal
    {
        $this->assertEditable($proposal);

        DB::transaction(function () use ($proposal, $sanctionedPostCount, $vacancies): void {
            $proposal->forceFill(['sanctioned_post_count' => $sanctionedPostCount])->save();

            $proposal->vacancies()->delete();
            foreach ($vacancies as $vacancy) {
                $proposal->vacancies()->create([
                    'vacancy_type_id' => $vacancy['vacancy_type_id'],
                    'reservation_category_id' => $vacancy['reservation_category_id'],
                    'count' => $vacancy['count'],
                ]);
            }
        });

        return $proposal->fresh(['vacancies']) ?? $proposal;
    }

    /**
     * Working officers on the target post (PPT S16) — M4 Phase 3. Each row
     * is a proposal-time snapshot: plain columns, no live employee/HRMS
     * reference, so this proposal's record can never change because
     * upstream data changes later (architect directive).
     *
     * This is the shared entry point manual entry uses today and the
     * planned Excel import (M4 Phase 6) will call directly with its parsed
     * rows — the same array shape, the same persistence logic, no
     * duplication (architect directive).
     *
     * @param  array<int, array<string, mixed>>  $officers
     */
    public function saveWorkingOfficers(DpcProposal $proposal, array $officers, ?User $actor = null): DpcProposal
    {
        $this->assertEditable($proposal);

        DB::transaction(function () use ($proposal, $officers): void {
            $proposal->workingOfficers()->delete();

            foreach ($officers as $officer) {
                $proposal->workingOfficers()->create([
                    'sn' => $officer['sn'],
                    'employee_number' => $officer['employee_number'] ?? null,
                    'name' => $officer['name'],
                    'dob' => $officer['dob'],
                    'qualification' => $officer['qualification'] ?? null,
                    'current_post' => $officer['current_post'] ?? null,
                    'feeder_post' => $officer['feeder_post'] ?? null,
                    'date_of_joining_service' => $officer['date_of_joining_service'] ?? null,
                    'date_of_posting_on_post' => $officer['date_of_posting_on_post'] ?? null,
                    'date_of_posting_on_feeder_post' => $officer['date_of_posting_on_feeder_post'] ?? null,
                    'reservation_category_id' => $officer['reservation_category_id'],
                    'roster_position' => $officer['roster_position'] ?? null,
                    'deputation_date' => $officer['deputation_date'] ?? null,
                ]);
            }
        });

        $this->recordSectionUpdate($proposal, 'working_officers_updated', 'Working officers updated', count($officers), $actor);

        return $proposal->fresh(['workingOfficers']) ?? $proposal;
    }

    /**
     * Seniority List (PPT S17) — M4 Phase 4. Same discipline as
     * `saveWorkingOfficers()`: a proposal-time snapshot, no live employee
     * reference, and the shared entry point manual entry uses today and
     * the planned Excel import (M4 Phase 6) will call directly with its
     * parsed rows.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function saveSeniorityEntries(DpcProposal $proposal, array $entries, ?User $actor = null): DpcProposal
    {
        $this->assertEditable($proposal);

        DB::transaction(function () use ($proposal, $entries): void {
            $proposal->seniorityEntries()->delete();

            foreach ($entries as $entry) {
                $proposal->seniorityEntries()->create([
                    'seniority_number' => $entry['seniority_number'],
                    'employee_number' => $entry['employee_number'] ?? null,
                    'name' => $entry['name'],
                    'dob' => $entry['dob'],
                    'qualification' => $entry['qualification'] ?? null,
                    'current_post' => $entry['current_post'] ?? null,
                    'feeder_post' => $entry['feeder_post'] ?? null,
                    'reservation_category_id' => $entry['reservation_category_id'],
                    'date_of_joining_service' => $entry['date_of_joining_service'] ?? null,
                    'date_of_posting_on_current_post' => $entry['date_of_posting_on_current_post'] ?? null,
                    'date_of_posting_on_feeder_post' => $entry['date_of_posting_on_feeder_post'] ?? null,
                ]);
            }
        });

        // **The Seniority List is the source of truth for who is evaluated**
        // (business directive, 2026-08-22), so saving it rebuilds the evaluation
        // candidate list from these very rows. Evaluation data already entered
        // survives for officers who are still on the list — see
        // EvaluationCandidateService::keyOf() for how an officer is recognised
        // across two versions of it.
        //
        // Outside the transaction above, deliberately: the projection opens its
        // own, and a nested transaction here would make a failed projection roll
        // back a seniority list the department had legitimately saved.
        $this->candidates->project($proposal->fresh() ?? $proposal);

        $this->recordSectionUpdate($proposal, 'seniority_list_updated', 'Seniority list updated', count($entries), $actor);

        return $proposal->fresh(['seniorityEntries']) ?? $proposal;
    }

    /**
     * Evaluation / Tabulation Chart (PPT S18) — M4 Phase 5, **reworked by the
     * business directive of 2026-08-22.**
     *
     * **This no longer writes employee identity.** The chart's officers and all
     * ten of their identity fields are projected from the approved Seniority List
     * when that list is saved; this method updates only what evaluation is
     * actually about — CR grades, loyalty, immovable property, tribal-area
     * service and the note. Identity fields in `$rows` are **ignored**, and an
     * `employee_number` that disagrees with the Seniority List is **refused**.
     *
     * Before this, S16, S17 and S18 each carried the same ten fields and nothing
     * reconciled them: an officer could hold one date of birth on the seniority
     * list and another on the chart, and because the chart is what the engine
     * scores, the least-verified copy was the one that decided the promotion.
     *
     * **The officer set is immutable here.** A payload that adds, drops or
     * renames an officer throws rather than being reconciled — enforced at this
     * layer as well as in the Form Request, because the Excel import calls
     * straight into here and never sees a request.
     *
     * Loyalty, Immovable Property and CR are still written per year (decision
     * Q4), and nothing here derives a mark, a total, an eligibility verdict or a
     * zone size — the chart is stored exactly as entered and the M5 Eligibility
     * Engine computes from it (architect directive).
     *
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws WorkflowException when the officers do not match the Seniority List
     */
    public function saveEvaluationRows(DpcProposal $proposal, array $rows, ?User $actor = null): DpcProposal
    {
        $this->assertEditable($proposal);

        $this->candidates->applyEvaluationData($proposal, $rows);

        $this->recordSectionUpdate($proposal, 'evaluation_chart_updated', 'Evaluation chart updated', count($rows), $actor);

        return $proposal->fresh(['evaluationRows.crGrades', 'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags']) ?? $proposal;
    }

    /**
     * One Activity Timeline entry per officer-section save (architect
     * Decision 4, 2026-08-13) — the committee section has done this since
     * Phase 2; working officers, seniority, and the evaluation chart now use
     * the identical pattern. For a system whose output is contested in
     * CAT/HC, "who last changed the evaluation chart, and when" has to be
     * answerable without reconstructing it from field-level audit rows.
     *
     * The actor is optional because ActivityTimelineService falls back to the
     * authenticated user; passing it explicitly keeps attribution correct for
     * callers that are not a plain HTTP request.
     */
    private function recordSectionUpdate(DpcProposal $proposal, string $event, string $description, int $rowCount, ?User $actor): void
    {
        $this->timeline->record(
            "dpc_proposal.{$event}",
            $proposal,
            "{$description} ({$rowCount} rows)",
            ['row_count' => $rowCount],
            $actor,
        );
    }

    /**
     * Final workflow validation (M4 Phase 7): the PPT's S14→S18 sequence must
     * actually be finished before the Administrative Head is asked to review
     * and sign it. Enforced here rather than only in the controller, so the
     * gate holds for any future caller.
     */
    public function submit(DpcProposal $proposal, User $actor, Request $request): void
    {
        $missing = $this->completeness->missingSections($proposal);

        if ($missing !== []) {
            throw new WorkflowException(
                'This proposal cannot be submitted yet — the following section(s) are incomplete: '
                .implode('; ', $missing).'.'
            );
        }

        DB::transaction(function () use ($proposal, $actor, $request): void {
            $locked = $this->lock($proposal);
            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_proposal', 'submit', $from, $actor);

            $locked->forceFill([
                'status' => DpcProposalStatus::Submitted->value,
                'submitted_at' => now(),
                'prepared_by' => $actor->id,
            ])->save();

            $this->engine->record($locked, 'dpc_proposal', 'submit', $from, $def, $actor, $request, null, null, $locked->department_id);
        });

        $proposal->refresh();
    }

    public function approve(DpcProposal $proposal, User $actor, Request $request): void
    {
        $this->assertSignatureAvailable();

        DB::transaction(function () use ($proposal, $actor, $request): void {
            $locked = $this->lock($proposal);
            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_proposal', 'approve', $from, $actor);

            $signature = $this->signatures->sign(
                $actor,
                $locked,
                $this->renderDocument($locked),
                "dpc-proposal-{$locked->id}.txt",
            );

            $dpcCode = $this->dpcIds->next($locked->department()->firstOrFail(), $locked->proposal_date);

            $locked->forceFill([
                'status' => DpcProposalStatus::Approved->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'signature_id' => $signature->id,
                'dpc_code' => $dpcCode,
                'forwarded_at' => now(),
            ])->save();

            $this->engine->record($locked, 'dpc_proposal', 'approve', $from, $def, $actor, $request, null, $signature->id, $locked->department_id);
        });

        $proposal->refresh();
    }

    public function return(DpcProposal $proposal, User $actor, string $remarks, Request $request): void
    {
        DB::transaction(function () use ($proposal, $actor, $remarks, $request): void {
            $locked = $this->lock($proposal);
            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_proposal', 'return', $from, $actor);

            $locked->forceFill([
                'status' => DpcProposalStatus::Returned->value,
                'returned_at' => now(),
                'return_remarks' => $remarks,
            ])->save();

            $this->engine->record($locked, 'dpc_proposal', 'return', $from, $def, $actor, $request, $remarks, null, $locked->department_id);
        });

        $proposal->refresh();
    }

    public function cancel(DpcProposal $proposal, User $actor, Request $request): void
    {
        DB::transaction(function () use ($proposal, $actor, $request): void {
            $locked = $this->lock($proposal);
            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_proposal', 'cancel', $from, $actor);

            $locked->forceFill(['status' => DpcProposalStatus::Cancelled->value, 'cancelled_at' => now()])->save();

            $this->engine->record($locked, 'dpc_proposal', 'cancel', $from, $def, $actor, $request, null, null, $locked->department_id);
        });

        $proposal->refresh();
    }

    private function lock(DpcProposal $proposal): DpcProposal
    {
        return DpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Enforced at the Service layer, not just the Policy/HTTP layer
     * (architect directive: sections must become immutable once a
     * proposal is no longer DRAFT/RETURNED) — so a future caller that
     * bypasses the controller (e.g. the planned Excel import job) can't
     * accidentally mutate a submitted proposal.
     */
    private function assertEditable(DpcProposal $proposal): void
    {
        if (! $proposal->status->isEditable()) {
            throw new WorkflowException('This section can no longer be edited — the proposal is not in Draft/Returned status.');
        }
    }

    /**
     * AH approval is a legally significant signature, so production must
     * never sign with the mock provider (architect ruling, 2026-08-13).
     *
     * Keyed on the feature flag rather than on a provider name: the flag is
     * the single switch that decides which provider is bound, so checking
     * anything else could drift out of step with what actually signs. Outside
     * production the mock is the intended provider and approval proceeds
     * normally, which is what keeps UAT demonstrable end to end while CSC's
     * API is still outstanding.
     */
    private function assertSignatureAvailable(): void
    {
        if (app()->environment('production') && Feature::CscDigiSign->disabled()) {
            throw new WorkflowException(
                'Digital signing is unavailable: CSC DigiSign is not enabled on this environment, and a DPC proposal '
                .'may not be approved without a legally valid digital signature. Contact the system administrator.'
            );
        }
    }

    /**
     * The document the Administrative Head actually signs (M4 Phase 7).
     *
     * A single approval covers every section (architect decision), so the
     * signed artefact has to *contain* every section — otherwise the AH's
     * signature attests to a header while the committee, officers, seniority
     * list, and evaluation chart it approved live only in mutable database
     * rows. This renders the whole proposal as it stood at the moment of
     * signing, which is what makes the signature evidentially useful if the
     * promotion is later contested.
     *
     * Mark and Remark are absent by design — they are M5 outputs and are not
     * part of what the department proposes.
     */
    private function renderDocument(DpcProposal $proposal): string
    {
        $proposal->loadMissing(
            'department', 'postToCadre', 'postFromCadre', 'dpcType',
            'committeeMembers', 'vacancies.vacancyType', 'vacancies.reservationCategory',
            'workingOfficers.reservationCategory', 'seniorityEntries.reservationCategory',
            'evaluationRows.reservationCategory', 'evaluationRows.crGrades',
            'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags',
        );

        $date = static fn (?Carbon $value): string => $value?->format('d-m-Y') ?? '—';
        $lines = [];

        $lines[] = 'DETAILED DPC PROPOSAL (विस्तृत डी.पी.सी. प्रस्ताव)';
        $lines[] = str_repeat('=', 70);
        $lines[] = 'Department: '.($proposal->department->name ?? "#{$proposal->department_id}");
        $lines[] = "Proposal Reference: #{$proposal->id}";
        $lines[] = 'Generated At: '.now()->format('d-m-Y H:i:s');
        $lines[] = '';

        $lines[] = '1. SELECTION OF POSTS (S14)';
        $lines[] = "   Type of DPC: {$proposal->dpcType->name_en}";
        $lines[] = "   Post To:   {$proposal->postToCadre->name} (Class {$proposal->postToCadre->class_level->value})";
        $lines[] = "   Post From: {$proposal->postFromCadre->name} (Class {$proposal->postFromCadre->class_level->value})";
        $lines[] = '   Proposal Date: '.$date($proposal->proposal_date);
        $lines[] = '   Legal Stay in Effect: '.($proposal->legal_stay ? 'Yes' : 'No');
        $lines[] = '';

        $lines[] = '2. DPC COMMITTEE (S15)';
        foreach ($proposal->committeeMembers as $index => $member) {
            $lines[] = sprintf(
                '   %d. %s | %s | %s | %s | %s',
                $index + 1,
                $member->name,
                $member->designation ?? '—',
                $member->mobile ?? '—',
                $member->email ?? '—',
                $member->capacity->label(),
            );
        }
        $lines[] = '';

        $lines[] = '3. WORKING OFFICERS & VACANCIES (S16)';
        $lines[] = '   Sanctioned Post Count: '.($proposal->sanctioned_post_count ?? '—');
        foreach ($proposal->vacancies as $vacancy) {
            $lines[] = "   Vacancy — {$vacancy->vacancyType->name_en} / {$vacancy->reservationCategory->code}: {$vacancy->count}";
        }
        foreach ($proposal->workingOfficers as $officer) {
            $lines[] = sprintf(
                '   %d. %s | DOB %s | %s | Roster %s',
                $officer->sn,
                $officer->name,
                $date($officer->dob),
                $officer->reservationCategory->code,
                $officer->roster_position ?? '—',
            );
        }
        $lines[] = '';

        $lines[] = '4. FINAL SENIORITY LIST (S17)';
        foreach ($proposal->seniorityEntries as $entry) {
            $lines[] = sprintf(
                '   %d. %s | DOB %s | %s | Joined %s | Posted on feeder %s | %s',
                $entry->seniority_number,
                $entry->name,
                $date($entry->dob),
                $entry->qualification ?? '—',
                $date($entry->date_of_joining_service),
                $date($entry->date_of_posting_on_feeder_post),
                $entry->reservationCategory->code,
            );
        }
        $lines[] = '';

        $lines[] = '5. EVALUATION / TABULATION CHART (S18)';
        $lines[] = '   (Mark and Remark are computed by the Commission and are not part of this proposal.)';
        foreach ($proposal->evaluationRows as $row) {
            $cr = $row->crGrades->map(fn ($grade): string => $grade->year.':'.($grade->grade ?? '—'))->implode(' ');
            $loyalty = $row->loyaltyFlags->map(fn ($flag): string => $flag->year.':'.($flag->flag === null ? '—' : ($flag->flag ? 'Y' : 'N')))->implode(' ');
            $property = $row->propertyFlags->map(fn ($flag): string => $flag->year.':'.($flag->flag === null ? '—' : ($flag->flag ? 'Y' : 'N')))->implode(' ');

            $lines[] = sprintf('   %d. %s | DOB %s | %s | DOP %s', $row->sn, $row->name, $date($row->dob), $row->reservationCategory->code, $date($row->date_of_posting_on_feeder_post));
            $lines[] = "      CR: {$cr}";
            $lines[] = "      Loyalty: {$loyalty}";
            $lines[] = "      Immovable Property: {$property}";
            $lines[] = '      Note: '.($row->note ?? '—').' | Tribal-area service (yrs): '.($row->tribal_area_service_years ?? '—');
        }
        $lines[] = '';

        $lines[] = str_repeat('=', 70);
        $lines[] = 'Approved and digitally signed by the Administrative Head, and forwarded to';
        $lines[] = 'the Chhattisgarh Public Service Commission (S19).';

        return implode("\n", $lines);
    }
}
