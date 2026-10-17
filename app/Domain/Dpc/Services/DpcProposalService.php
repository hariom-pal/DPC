<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\PreDpc\Models\PreDpcPromotionSource;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Services\IdentifierSequenceService;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\SignatureService;
use App\Domain\UserManagement\Services\PromotionCandidateProvisioner;
use App\Enums\Feature;
use App\Enums\MasterStatus;
use Carbon\CarbonInterface;
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
 *
 * **The committee (S15) is no longer here.** It moved to
 * {@see DpcCommitteeService} on 2026-08-26, when the register of that date gave
 * it three further operations with three different actors — the CGPSC Chairman
 * designates who conducts the DPC, the CGPSC Administrator may substitute a member
 * at any stage, and the system seats the Chairman on every committee. A section
 * with its own actors and its own lifecycle does not belong beside the vacancy
 * table.
 */
class DpcProposalService
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly SignatureService $signatures,
        private readonly IdentifierSequenceService $identifiers,
        private readonly DpcRevisionService $revisions,
        private readonly PromotionCandidateProvisioner $candidateAccounts,
        private readonly ActivityTimelineService $timeline,
        private readonly DpcProposalCompletenessService $completeness,
        private readonly EvaluationCandidateService $candidates,
        private readonly VacancyEngine $vacancyEngine,
        private readonly VacancyCalculationRecorder $vacancyCalculations,
    ) {}

    /**
     * Raise a DPC from an active approved Pre-DPC (change items 11, 12, 18).
     *
     * **Most of the old form is gone**, because the register says the AO should not
     * enter it again: Department, DPC Year, Promotion To Post, Recruitment Rule,
     * Class, Pay Scale and Promotion Duration all come from the Pre-DPC, and the
     * Proposal Date is generated. What the officer still chooses is the Type of DPC,
     * whether a legal stay is in force, and — since multiple feeders were restored on
     * 2026-08-25 — **which Promotion From Post this DPC is for**.
     *
     * **Why the feeder is chosen rather than inherited.** An order may sanction
     * promotion into Deputy Director from Assistant Director, Senior Lecturer *and*
     * Research Officer. A DPC is conducted on one seniority list, of one feeder post,
     * so the order alone cannot say which. It is offered from the order's own sources
     * and refused if it names anything else — the officer chooses among what the
     * Government Order sanctioned, never outside it.
     *
     * **The DPC Number is issued here, not at approval** (item 18). A proposal that
     * has been returned twice is still `DPC-2026-HED-0001`, and it has to be
     * citable before its first approval for that to be true.
     *
     * **The values are copied, not referenced.** `pre_dpc_proposal_id` records
     * *which* order sanctioned this promotion; the copies record what that order
     * said at the time. An amendment to the Pre-DPC next year must not restate the
     * terms a concluded promotion ran under.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromPreDpc(PreDpcProposal $preDpc, User $actor, array $data): DpcProposal
    {
        return DB::transaction(function () use ($preDpc, $actor, $data): DpcProposal {
            $department = $preDpc->department()->firstOrFail();
            $source = $this->sourceFor($preDpc, $data);

            $proposal = new DpcProposal([
                'department_id' => $department->id,
                'pre_dpc_proposal_id' => $preDpc->id,
                'dpc_year' => $preDpc->dpc_year,
                'dpc_type_id' => $data['dpc_type_id'],
                'promotion_to_post_id' => $preDpc->promotion_to_post_id,
                'promotion_from_post_id' => $source->promotion_from_post_id,
                'pay_scale_id' => $preDpc->pay_scale_id,
                'class_level' => $preDpc->class_level?->value,

                // The qualifying service the order sanctioned **for this feeder** —
                // five years as an Assistant Director is not five years as a
                // Research Officer.
                'promotion_duration_years' => $source->promotion_duration_years,
                'recruitment_rule_id' => $this->ruleInForce($preDpc),
                'legal_stay' => $data['legal_stay'] ?? false,

                /*
                 | Item 12: the Proposal Date is always generated.
                 |
                 | It is the date the department raised the proposal, which is
                 | today by definition — a typed date could only ever be wrong,
                 | and a back-dated one changes which DPC Year serial the number
                 | falls in.
                 */
                'proposal_date' => Carbon::today(),

                'status' => DpcProposalStatus::Draft->value,
                'revision_number' => 1,
                'dpc_code' => $this->identifiers->next(
                    $department,
                    IdentifierSequenceService::SERIES_DPC,
                    (int) ($preDpc->dpc_year ?? now()->format('Y')),
                ),
                'prepared_by' => $actor->id,
            ]);

            $proposal->save();

            $this->revisions->open($proposal, $actor);

            return $proposal;
        });
    }

    /**
     * The two things the officer may still change on a draft.
     *
     * Everything else is the Pre-DPC's and is not editable here — see
     * {@see createFromPreDpc()}. If the sanctioned terms are wrong, the Pre-DPC is
     * amended and a new DPC raised from it; editing them on the proposal would
     * produce a promotion whose terms no order sanctioned.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(DpcProposal $proposal, array $data): DpcProposal
    {
        $this->assertEditable($proposal);

        $proposal->fill([
            'dpc_type_id' => $data['dpc_type_id'],
            'legal_stay' => $data['legal_stay'] ?? false,
        ])->save();

        return $proposal;
    }

    /**
     * Which of the order's Promotion From Posts this DPC is for.
     *
     * Defaults to the only one when an order names a single feeder, so a department
     * whose promotions are single-source never sees the question. When there are
     * several the choice is required, and it is validated against the order rather
     * than trusted: a posted id the Government Order does not sanction would be a DPC
     * for a promotion nobody authorised.
     *
     * @param  array<string, mixed>  $data
     */
    private function sourceFor(PreDpcProposal $preDpc, array $data): PreDpcPromotionSource
    {
        $sources = $preDpc->promotionSources;

        if ($sources->isEmpty()) {
            throw new WorkflowException(
                'That Pre-DPC names no Promotion From Post, so there is nothing to raise a DPC from.'
            );
        }

        $chosen = isset($data['promotion_from_post_id']) ? (int) $data['promotion_from_post_id'] : null;

        if ($chosen === null) {
            if ($sources->count() > 1) {
                throw new WorkflowException(
                    'This Pre-DPC sanctions promotion from more than one post. Choose which Promotion From Post '
                    .'this DPC is for.'
                );
            }

            return $sources->first();
        }

        $source = $sources->firstWhere('promotion_from_post_id', $chosen);

        if ($source === null) {
            throw new WorkflowException(
                'That Promotion From Post is not one the selected Pre-DPC sanctions. Choose one it names.'
            );
        }

        return $source;
    }

    /**
     * The recruitment-rule version in force for the Promotion To Post.
     *
     * Recorded on the proposal so a concluded DPC can still say which rule it ran
     * under: rules are versioned and never overwritten, so "the current rule" a
     * year later is a different row. Null when the post has no rule yet, which is
     * possible for a Pre-DPC approved in the same request — and is not worth
     * refusing over, because the engine reads the rule at calculation time.
     */
    private function ruleInForce(PreDpcProposal $preDpc): ?int
    {
        return $this->currentRuleFor($preDpc->promotion_to_post_id);
    }

    /**
     * Whether the rule recorded on this proposal is still the current one
     * (suggestion 9).
     *
     * **Reported, never applied.** The recorded rule stays as it is: a DPC must run
     * under the terms that sanctioned it, and a rule that changed underneath a
     * draft must not silently rewrite a proposal an officer has already filled in.
     * But a draft can sit for weeks while an amended Pre-DPC supersedes the order
     * behind it, and nothing told the Authorized Officer — they found out when the
     * Administrative Head asked.
     *
     * Whether a superseded rule should *block* submission is a business question
     * and is not decided here.
     */
    public function recruitmentRuleSuperseded(DpcProposal $proposal): bool
    {
        // Only `recruitment_rule_id` is nullable — a proposal always names the post
        // it promotes into, so there is nothing to guard there.
        if ($proposal->recruitment_rule_id === null) {
            return false;
        }

        $current = $this->currentRuleFor($proposal->promotion_to_post_id);

        // A post whose rule has been withdrawn entirely is not "superseded" — there
        // is nothing newer to point at, and saying so would send the officer to
        // look for a rule that does not exist.
        return $current !== null && $current !== $proposal->recruitment_rule_id;
    }

    private function currentRuleFor(int $postId): ?int
    {
        $id = RecruitmentRule::query()
            ->where('post_id', $postId)
            ->where('status', MasterStatus::Active->value)
            ->orderByDesc('version')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Vacancy breakdown (PPT S16). Strictly row-based: one row per (vacancy type,
     * reservation category) pair (architect directive — never separate columns per
     * type). `sanctioned_post_count` lives on the proposal itself; it is captured
     * on this same screen.
     *
     * **The rows are derived, not passed in** (business directive, 2026-09-02) —
     * see the note inside.
     */
    public function saveVacancies(DpcProposal $proposal, ?User $actor = null): DpcProposal
    {
        $this->assertEditable($proposal);

        DB::transaction(function () use ($proposal): void {
            /*
             | The sanctioned strength is **read, not received** (change register of
             | 2026-08-27, item 1).
             |
             | It used to be an argument, typed on this screen. The register is
             | explicit that the AO should not enter vacancy figures, and a
             | sanctioned strength is not the officer's number to give: it is what a
             | Government Order says, and the Department Post Mapping is where this
             | portal keeps that.
             |
             | It is still *written to the proposal*, as a snapshot. A concluded DPC
             | ran against the strength recorded at the time, and reading it live
             | would let a mapping superseded next year change what a signed
             | proposal says it was calculated from.
             */
            $breakdown = $this->vacancyEngine->calculate($proposal);

            $proposal->forceFill([
                'sanctioned_post_count' => $breakdown->sanctionedStrength,
            ])->save();

            /*
             | **The rows are no longer an argument either** (business directive,
             | 2026-09-02). They are derived from the Currently Working chart by
             | `VacancyEngine::classification()`, so the breakdown cannot disagree
             | with the total the same engine calculated — the disagreement the old
             | balance check existed to catch is now unrepresentable.
             |
             | Rewritten wholesale on every save rather than diffed: the chart is
             | itself replaced wholesale, and a partial update would leave rows
             | describing officers the save has just removed.
             */
            $proposal->vacancies()->delete();

            foreach ($this->vacancyEngine->classification($proposal) as $vacancy) {
                $proposal->vacancies()->create($vacancy);
            }
        });

        /*
         | The append-only audit row (change register of 2026-08-27, item 10).
         |
         | Recorded **only when the calculation resolves**: the recorder itself
         | refuses a partial row, and a proposal whose post mapping carries no
         | sanctioned strength — or whose post has no recruitment rule yet — can
         | still have its chart saved. The screen shows the incomplete breakdown
         | without persisting it, which is the recorder's own stated contract.
         */
        if ($this->vacancyEngine->calculate($proposal->refresh())->isComplete()) {
            $this->vacancyCalculations->record($proposal, $actor);
        }

        $this->recordSectionUpdate($proposal, 'vacancies_updated', 'Vacancy breakdown derived from the chart', $proposal->vacancies()->count(), $actor);

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

        // The Administrator's Vacancy Management record is the source for this
        // proposal: the AO enters no working strength and no vacancy breakdown.
        if ($proposal->usesVacancyMaster()) {
            throw new WorkflowException(__('vacancymaster.proposal.ao_cannot_enter'));
        }

        DB::transaction(function () use ($proposal, $officers): void {
            $proposal->workingOfficers()->delete();

            foreach ($officers as $officer) {
                $proposal->workingOfficers()->create([
                    'sn' => $officer['sn'],
                    'status' => $officer['status'] ?? DpcWorkingOfficer::STATUS_WORKING,
                    'source' => $officer['source'] ?? null,
                    'employee_number' => $officer['employee_number'] ?? null,
                    'name' => $officer['name'] ?? null,
                    'dob' => $officer['dob'] ?? null,
                    'qualification' => $officer['qualification'] ?? null,
                    'promotion_to_post' => $this->postName($officer, 'to'),
                    'promotion_from_post' => $this->postName($officer, 'from'),
                    'date_of_joining_service' => $officer['date_of_joining_service'] ?? null,
                    'date_of_posting_on_promotion_to_post' => $officer['date_of_posting_on_promotion_to_post'] ?? null,
                    'date_of_posting_on_promotion_from_post' => $officer['date_of_posting_on_promotion_from_post'] ?? null,
                    'reservation_category_id' => $officer['reservation_category_id'],

                    // The roster point this chair is, beside the category of the
                    // officer sitting on it (business instruction, 2026-09-07).
                    'seat_reservation_category_id' => $officer['seat_reservation_category_id'] ?? null,
                    'mobile' => $officer['mobile'] ?? null,
                    'roster_position' => $officer['roster_position'] ?? null,
                    'date_of_retirement' => $officer['date_of_retirement'] ?? null,
                    'deputation_from' => $officer['deputation_from'] ?? null,
                    'deputation_to' => $officer['deputation_to'] ?? null,
                ]);
            }
        });

        $this->recordSectionUpdate($proposal, 'working_officers_updated', 'Working officers updated', count($officers), $actor);

        return $proposal->fresh(['workingOfficers']) ?? $proposal;
    }

    /**
     * What provisioning did on the last call to {@see saveSeniorityEntries()}.
     *
     * Held rather than returned because the method's return value is the proposal
     * and has been since M4. The accounts are a *consequence* of saving the list,
     * and the officer has to be told about them — temporary passwords are shown
     * once and never recoverable, so a caller that drops this silently creates
     * accounts nobody can sign in to. That is exactly what happened between
     * item 17 shipping and 2026-08-25.
     *
     * @var array{created: array<string, string>, reused: list<string>, skipped: list<string>, conflicts: list<string>}|null
     */
    private ?array $lastCandidateProvisioning = null;

    /**
     * @return array{created: array<string, string>, reused: list<string>, skipped: list<string>, conflicts: list<string>}|null
     */
    public function lastCandidateProvisioning(): ?array
    {
        return $this->lastCandidateProvisioning;
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
    public function saveSeniorityEntries(
        DpcProposal $proposal,
        array $entries,
        ?User $actor = null,
        ?CarbonInterface $listAsOn = null,
    ): DpcProposal {
        $this->assertEditable($proposal);

        DB::transaction(function () use ($proposal, $entries, $listAsOn): void {
            // Rule 1.6 — the date the department stated the list was drawn up as on.
            // Validated by the caller against the DPC year; recorded with the list
            // so the submission gate can tell a list saved for another DPC year.
            if ($listAsOn !== null) {
                $proposal->forceFill(['seniority_list_as_on' => $listAsOn->toDateString()])->save();
            }

            $proposal->seniorityEntries()->delete();

            foreach ($entries as $entry) {
                $proposal->seniorityEntries()->create([
                    'seniority_number' => $entry['seniority_number'],
                    'employee_number' => $entry['employee_number'] ?? null,
                    'name' => $entry['name'],
                    'dob' => $entry['dob'],
                    'qualification' => $entry['qualification'] ?? null,
                    'promotion_to_post' => $this->postName($entry, 'to'),
                    'promotion_from_post' => $this->postName($entry, 'from'),
                    // Optional on the simplified Seniority List (2026-10-17).
                    'reservation_category_id' => $entry['reservation_category_id'] ?? null,
                    'seat_reservation_category_id' => $entry['seat_reservation_category_id'] ?? null,
                    'mobile' => $entry['mobile'] ?? null,
                    'date_of_joining_service' => $entry['date_of_joining_service'] ?? null,
                    'date_of_posting_on_promotion_to_post' => $entry['date_of_posting_on_promotion_to_post'] ?? null,
                    'date_of_posting_on_promotion_from_post' => $entry['date_of_posting_on_promotion_from_post'] ?? null,
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

        /*
         | Change item 17: every officer on the Seniority List gets a login.
         |
         | Done here rather than at submission, because the register says the
         | officers *appearing in the Seniority List* must have access and this is
         | the moment they appear. Idempotent, so re-uploading the chart creates
         | nothing for officers who already have an account.
         |
         | Outside the transaction above deliberately. Provisioning accounts is a
         | consequence of saving the list, not part of it: an officer whose account
         | cannot be created — a duplicate employee number in the master, say — must
         | not cost the department the chart it just filed. What happens instead is
         | that the accounts are reported and the list is saved.
         */
        $this->lastCandidateProvisioning = $this->candidateAccounts->provision($proposal);

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

        /*
         | A Vacancy Management proposal stores, at submission, the promotion
         | vacancy the scoring engine fills — derived from the finalized record and
         | recorded in the vacancy-calculation audit like a chart's. Written before
         | the status changes, while the proposal is still the AO's.
         */
        if ($missing === [] && $proposal->usesVacancyMaster()) {
            $this->saveVacancies($proposal, $actor);
        }

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

                // The finalized Vacancy Master position this submission was
                // reconciled against, if one exists — so a later revision of the
                // position does not change what the proposal was checked with.
                'vacancy_position_id' => app(VacancyMasterService::class)->finalizedForProposal($locked)?->id,
            ])->save();

            // Item 18: the revision records that it was submitted, by whom and
            // when. The proposal's own `submitted_at` is the *latest* submission;
            // the revision row is the one that will not be overwritten by the next.
            $this->revisions->recordSubmission($locked, $actor);

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

            // The DPC Number is **not** minted here any more (change item 18): it
            // was issued when the proposal was created, and it is the same string
            // through every return and resubmission. Minting at approval would
            // have meant a returned proposal had no number to be discussed by.
            $locked->forceFill([
                'status' => DpcProposalStatus::Approved->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'signature_id' => $signature->id,
                'forwarded_at' => now(),
            ])->save();

            $this->revisions->recordApproval($locked);

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

            /*
             | Item 18: this closes the revision that was returned and opens the
             | next one, bumping `revision_number` — and leaves `dpc_code` alone.
             |
             | DPC-2026-HED-0001 Revision 1 returned becomes DPC-2026-HED-0001
             | Revision 2. The number is the promotion exercise; the revision is
             | which attempt at it is being read.
             */
            $this->revisions->recordReturn($locked, $actor, $remarks);

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

            $this->revisions->recordCancellation($locked);

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
            'department', 'promotionToPost', 'promotionFromPost', 'dpcType',
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
        $lines[] = "   Post To:   {$proposal->promotionToPost->name} (Class {$proposal->promotionToPost->class_level->value})";
        $lines[] = "   Post From: {$proposal->promotionFromPost->name} (Class {$proposal->promotionFromPost->class_level->value})";
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
                $date($entry->date_of_posting_on_promotion_from_post),
                $entry->reservationCategory->code ?? '—',
            );
        }
        $lines[] = '';

        $lines[] = '5. EVALUATION / TABULATION CHART (S18)';
        $lines[] = '   (Mark and Remark are computed by the Commission and are not part of this proposal.)';
        foreach ($proposal->evaluationRows as $row) {
            $cr = $row->crGrades->map(fn ($grade): string => $grade->year.':'.($grade->grade ?? '—'))->implode(' ');
            $loyalty = $row->loyaltyFlags->map(fn ($flag): string => $flag->year.':'.($flag->flag === null ? '—' : ($flag->flag ? 'Y' : 'N')))->implode(' ');
            $property = $row->propertyFlags->map(fn ($flag): string => $flag->year.':'.($flag->flag === null ? '—' : ($flag->flag ? 'Y' : 'N')))->implode(' ');

            $lines[] = sprintf('   %d. %s | DOB %s | %s | DOP %s', $row->sn, $row->name, $date($row->dob), $row->reservationCategory->code ?? '—', $date($row->date_of_posting_on_promotion_from_post));
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

    /**
     * The post name to snapshot on an officer row (DPC-MODULE-FIX-001).
     *
     * **A Post is a master entity and is never typed.** The screens send
     * `promotion_to_post_id` / `promotion_from_post_id` — an id chosen from the
     * Department Post master — and this resolves it to that master row's name.
     *
     * **The snapshot still stores the name, and that is deliberate rather than a
     * compromise.** Every officer table in this application is a point-in-time
     * snapshot with no live foreign key (the ten-field standard), so that a
     * concluded proposal cannot change because a master was edited afterwards. A
     * foreign key here would undo exactly the property the snapshot exists to
     * provide, and would need a schema change to get there. Resolving the id at
     * write time gives both: the officer cannot invent a post, and the record
     * still says what the post was called when the DPC ran.
     *
     * Falls back to the posted name when no id is given, because the three CSV
     * importers carry post **names** — a department filling in a spreadsheet has
     * no access to our primary keys. That is the same reasoning that makes
     * `reservation_category` a code on a sheet and an id on a form.
     *
     * @param  array<string, mixed>  $row
     */
    private function postName(array $row, string $side): ?string
    {
        $id = $row["promotion_{$side}_post_id"] ?? null;

        if (is_numeric($id)) {
            $name = DepartmentPost::query()->whereKey((int) $id)->value('name');

            if ($name !== null) {
                return (string) $name;
            }
        }

        $typed = $row["promotion_{$side}_post"] ?? null;

        return is_string($typed) && $typed !== '' ? $typed : null;
    }
}
