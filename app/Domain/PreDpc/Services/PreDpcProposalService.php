<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Services;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Enums\ExperienceUnit;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Services\PostAttributeResolver;
use App\Domain\Master\Services\RecruitmentRuleService;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcPromotionSource;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Services\IdentifierSequenceService;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Exceptions\SignatureVerificationFailed;
use App\Domain\Signature\Services\SignatureAttemptLog;
use App\Domain\Signature\Services\SignatureService;
use App\Enums\Feature;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pre-DPC Proposal workflow (PPT S7–S11) — one promotion exercise, drafted by the
 * Authorized Officer and approved by the Administrative Head over a signature.
 *
 * **Reshaped on 2026-08-24** (change items 8–11). A Pre-DPC is now a single post
 * pair for a single DPC Year, it carries a Pre-DPC Number from the moment it is
 * drafted, and approving one *supersedes* the previous approved Pre-DPC for the
 * same exercise instead of the department's previous one. Approved proposals stay
 * immutable; an amendment opens a new version.
 *
 * **The three things approval does, and why they are one transaction.** It signs
 * the proposal, it makes this the active Pre-DPC for the exercise (retiring the
 * incumbent), and it projects the exercise into the Recruitment Rule master. Split
 * apart, the failure modes are a signed proposal that is not active, an active
 * proposal the engine has no rule for, or two active proposals for one exercise —
 * which `pre_dpc_active_exercise_unique` would refuse, aborting a half-finished
 * approval.
 */
class PreDpcProposalService
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly SignatureService $signatures,
        private readonly RecruitmentRuleService $rules,
        private readonly PostAttributeResolver $postAttributes,
        private readonly IdentifierSequenceService $identifiers,
        private readonly ActivityTimelineService $timeline,
        private readonly ProposalAllotmentService $allotments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(Department $department, User $actor, array $data): PreDpcProposal
    {
        return DB::transaction(function () use ($department, $actor, $data): PreDpcProposal {
            $year = (int) $data['dpc_year'];

            $proposal = new PreDpcProposal([
                'department_id' => $department->id,

                /*
                 | The number is issued at draft, not at approval (item 10 read
                 | with item 18's doctrine for the DPC Number).
                 |
                 | An officer who telephones about "PDPC-2026-HED-0007" must be
                 | talking about something that exists, and a draft that has been
                 | returned twice is still the same proposal. Issuing at approval
                 | would leave every unapproved proposal unnameable.
                 */
                'pre_dpc_number' => $this->identifiers->next($department, IdentifierSequenceService::SERIES_PRE_DPC, $year),

                'dpc_year' => $year,
                'version' => $this->nextVersion($department->id, $data),
                'status' => PreDpcStatus::Draft->value,
                'government_order_number' => $data['government_order_number'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
                'prepared_by' => $actor->id,
            ]);

            $this->applyExercise($proposal, $department, $data);
            $proposal->save();

            // After the save: a source row needs the order's id. A Pre-DPC the
            // Department Admin opens carries none yet (A1, 2026-10-26): its AO adds
            // them, and submission refuses an order without one (guardComplete).
            if (($data['promotion_sources'] ?? []) !== []) {
                $this->applySources($proposal, $department, $data['promotion_sources']);
            }

            return $proposal->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(PreDpcProposal $proposal, array $data): PreDpcProposal
    {
        return DB::transaction(function () use ($proposal, $data): PreDpcProposal {
            $department = $proposal->department;

            $proposal->fill([
                'dpc_year' => (int) $data['dpc_year'],
                'government_order_number' => $data['government_order_number'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
            ]);

            $this->applyExercise($proposal, $department, $data);
            $proposal->save();

            $this->applySources($proposal, $department, $data['promotion_sources'] ?? []);

            return $proposal->refresh();
        });
    }

    public function submit(PreDpcProposal $proposal, User $actor, Request $request): void
    {
        // Completeness is checked before the transaction because it refuses with a
        // message about the *form*, not about a race.
        $this->guardComplete($proposal);
        $this->assertSignatureAvailable();

        // The AO signs what they submit (2026-10-27), verified against this eSign
        // transaction every time. A failed or mismatched eSign rolls everything
        // back: the draft stays a draft and no signature is recorded.
        $this->signed($actor, $proposal, 'submit', function () use ($proposal, $actor, $request): void {
            DB::transaction(function () use ($proposal, $actor, $request): void {
                $locked = $this->lock($proposal);
                $from = $locked->status->value;
                $def = $this->engine->assertAllowed('pre_dpc', 'submit', $from, $actor);
                $this->assertCurrentOfficer($actor, $locked, Role::AuthorizedOfficer);

                $signature = $this->signatures->signVerified(
                    $actor,
                    $locked,
                    $this->renderDocument($locked),
                    "pre-dpc-{$locked->pre_dpc_number}-v{$locked->version}-submission.txt",
                    ['action' => 'submit'],
                );

                $locked->forceFill(['status' => PreDpcStatus::Submitted->value, 'submitted_at' => now(), 'prepared_by' => $actor->id])->save();
                $this->engine->record($locked, 'pre_dpc', 'submit', $from, $def, $actor, $request, null, $signature->id, $locked->department_id);
            });
        });
    }

    public function approve(PreDpcProposal $proposal, User $actor, Request $request): void
    {
        // Checked before the transaction: refusing because no signature provider
        // is configured is a deployment fault, not a workflow one, and it should
        // not hold a row lock while it says so.
        $this->assertSignatureAvailable();

        $this->signed($actor, $proposal, 'approve', fn () => DB::transaction(function () use ($proposal, $actor, $request): void {
            $locked = $this->lock($proposal);
            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('pre_dpc', 'approve', $from, $actor);
            $this->assertCurrentOfficer($actor, $locked, Role::AdministrativeHead);

            $signature = $this->signatures->signVerified(
                $actor,
                $locked,
                $this->renderDocument($locked),
                "pre-dpc-{$locked->pre_dpc_number}-v{$locked->version}.txt",
                ['action' => 'approve'],
            );

            $this->supersedeIncumbent($locked);

            $locked->forceFill([
                'status' => PreDpcStatus::Approved->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'signature_id' => $signature->id,
                'active_flag' => 1,
            ])->save();

            $this->projectToMasters($locked);
            $this->engine->record($locked, 'pre_dpc', 'approve', $from, $def, $actor, $request, null, $signature->id, $locked->department_id);
        }));
    }

    public function return(PreDpcProposal $proposal, User $actor, string $remarks, Request $request): void
    {
        DB::transaction(function () use ($proposal, $actor, $remarks, $request): void {
            $locked = $this->lock($proposal);
            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('pre_dpc', 'return', $from, $actor);

            $locked->forceFill(['status' => PreDpcStatus::Returned->value, 'returned_at' => now(), 'return_remarks' => $remarks])->save();
            $this->engine->record($locked, 'pre_dpc', 'return', $from, $def, $actor, $request, $remarks, null, $locked->department_id);
        });
    }

    public function cancel(PreDpcProposal $proposal, User $actor, Request $request): void
    {
        DB::transaction(function () use ($proposal, $actor, $request): void {
            $locked = $this->lock($proposal);
            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('pre_dpc', 'cancel', $from, $actor);

            $locked->forceFill(['status' => PreDpcStatus::Cancelled->value, 'cancelled_at' => now()])->save();
            $this->engine->record($locked, 'pre_dpc', 'cancel', $from, $def, $actor, $request, null, null, $locked->department_id);
        });
    }

    /**
     * Discard a draft (business instruction, 2026-09-06).
     *
     * > *Give an option to delete a draft Pre-DPC. However we will always make it a
     * > soft delete, and only a draft Pre-DPC can be deleted.*
     *
     * **Soft, always.** `PreDpcProposal` uses `SoftDeletes`, so the row and its
     * promotion sources stay in the database with a `deleted_at`. That is not a
     * technicality: the draft consumed a Pre-DPC Number from the department's
     * sequence, and a number that had been issued and then vanished without trace
     * would leave a hole nobody could account for. The row still answers what
     * `PDPC-2026-HED-0007` was.
     *
     * **Refused past draft, by the policy rather than here.** {@see cancel()} is the
     * ending for a proposal that has been seen — it leaves a cancelled row on the
     * record, with a status and a transition saying so. This is for one that has
     * not been seen by anybody.
     *
     * The status guard is repeated all the same. A service that trusts its caller
     * to have asked the policy is one route registration away from not being asked.
     */
    public function delete(PreDpcProposal $proposal, User $actor, Request $request): void
    {
        DB::transaction(function () use ($proposal, $actor, $request): void {
            $locked = $this->lock($proposal);

            if ($locked->status !== PreDpcStatus::Draft) {
                throw new WorkflowException(__('predpc.validation.only_a_draft_can_be_deleted'));
            }

            /*
             | Recorded on the timeline and not on the workflow ledger.
             |
             | The ledger holds *transitions* — a row per movement between two states
             | of the `pre_dpc` workflow — and deleting is not one: there is no state
             | on the far side of it. Writing a transition to a state the workflow
             | does not define would make the ledger stop being a description of the
             | workflow.
             */
            $this->timeline->record(
                'pre_dpc.deleted',
                $locked,
                sprintf('Draft %s deleted by %s', $locked->pre_dpc_number ?? 'proposal', $actor->name),
                [
                    'pre_dpc_number' => $locked->pre_dpc_number,
                    'uuid' => $locked->uuid,
                    'ip' => $request->ip(),
                ],
                $actor,
            );

            /*
             | The proposal alone. `PreDpcPromotionSource` carries no `deleted_at`,
             | so deleting the rows would be a *hard* delete — the one thing the
             | instruction rules out — and they are what say which posts the draft
             | drew on. They stay attached to the soft-deleted parent, which is
             | enough to hide them: every path to a source runs through the
             | proposal, and the proposal is gone from every ordinary query.
             */
            $locked->delete();
        });
    }

    /**
     * Amend an approved proposal: clone into a fresh Draft version of the same
     * exercise.
     *
     * The clone keeps the exercise (department, from post, to post, year) and the
     * sanctioned terms, and gets a **new Pre-DPC Number**. That is the deliberate
     * difference from a DPC revision (item 18): a DPC that is returned for
     * correction keeps its number because it is the same proposal being fixed,
     * whereas an amended Pre-DPC is a fresh Government Order superseding an
     * approved one, and the two orders must be separately citable.
     */
    public function amend(PreDpcProposal $proposal, User $actor): PreDpcProposal
    {
        return DB::transaction(function () use ($proposal, $actor): PreDpcProposal {
            $draft = new PreDpcProposal($proposal->only([
                'department_id',
                'dpc_year',
                'promotion_to_post_id',
                'pay_scale_id',
                'direct_recruitment_pct',
                'promotion_recruitment_pct',
                'government_order_number',
                'effective_from',
            ]));

            $draft->fill([
                'class_level' => $proposal->class_level?->value,
                'pre_dpc_number' => $this->identifiers->next(
                    $proposal->department,
                    IdentifierSequenceService::SERIES_PRE_DPC,
                    (int) $proposal->dpc_year,
                ),
                'version' => $this->nextVersion($proposal->department_id, $proposal->exerciseKey()),
                'parent_version_id' => $proposal->id,
                'status' => PreDpcStatus::Draft->value,
                'prepared_by' => $actor->id,
            ]);

            /*
             | Set outside `fill()` because `active_flag` is not mass-assignable, and
             | deliberately so: it is the item-9 uniqueness flag, and the one thing
             | that must never be settable from a request payload is which order is
             | in force.
             |
             | Stated rather than left to the column default. An amendment is a draft
             | *of an exercise that already has an order in force* — writing the null
             | explicitly is what says the amendment does not supersede anything until
             | it is itself approved.
             */
            $draft->forceFill(['active_flag' => null]);

            $draft->save();

            // The amendment stays with the officers the order was allotted to (A2).
            $this->allotments->inherit($draft, $proposal, $actor);

            // Every feeder the approved order named, with its terms as sanctioned.
            // An amendment starts from what is in force and is edited from there.
            foreach ($proposal->promotionSources as $source) {
                $copy = $draft->promotionSources()->create($source->only([
                    'promotion_from_post_id',
                    'promotion_percentage',
                    'min_experience_years',
                    'experience_unit',
                    'promotion_duration_years',
                    'sort_order',
                ]));

                /*
                 | The accepted qualifications are a pivot since 2026-08-26 (change
                 | register item 11), so `only()` cannot carry them. An amendment
                 | that silently dropped them would start from an order that
                 | required no degree, which is not what is in force.
                 */
                $copy->qualifications()->attach(
                    $source->qualifications->mapWithKeys(fn ($qualification, $index): array => [
                        $qualification->id => ['sort_order' => $index],
                    ])->all(),
                );
            }

            return $draft;
        });
    }

    /**
     * Write the exercise and everything it determines onto the proposal.
     *
     * **The three derived values are read from the posts, never from the
     * request** (item 5). The screen shows them as soon as both posts are chosen
     * and posts them back for the officer's benefit; trusting that would let a
     * crafted request sanction a pay scale the Commission never approved. The
     * resolver is also what refuses a post belonging to another department, which
     * is why it is asked here rather than only in the Form Request.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyExercise(PreDpcProposal $proposal, Department $department, array $data): void
    {
        $toPost = $this->postAttributes->resolve(
            $department->id,
            isset($data['promotion_to_post_id']) ? (int) $data['promotion_to_post_id'] : null,
        );

        if ($toPost === null) {
            throw new WorkflowException(
                'The Promotion To Post must be a live post of this department. '
                .'Register it in the Department Post master first.'
            );
        }

        $proposal->fill([
            'promotion_to_post_id' => $toPost->post->id,

            /*
             | The Pay Scale and Class recorded on the *order* are the To Post's.
             |
             | Not a display choice. `class_level` on the order described the
             | promotion post, and until 2026-08-26 `MemberLevelRule` read it as the
             | class of the post being promoted into (item 16), and the DPC inherits
             | both. What a *reader* needs beside each feeder is that feeder's own
             | scale and class, and those are on the source rows.
             */
            'pay_scale_id' => $toPost->payScaleId,
            'class_level' => $toPost->classLevel->value,

            'direct_recruitment_pct' => $data['direct_recruitment_pct'] ?? 0,
            'promotion_recruitment_pct' => $data['promotion_recruitment_pct'] ?? 0,
        ]);
    }

    /**
     * Replace the order's Promotion From Posts with the ones submitted.
     *
     * **Replaced wholesale rather than reconciled row by row.** A draft order's
     * feeder list is small and is edited by adding and removing rows; matching
     * submitted rows to stored ones by id would buy nothing and would be the place a
     * removed feeder quietly survived. The order is a draft while this runs — an
     * approved one is immutable — so there is nothing to preserve.
     *
     * @param  list<array<string, mixed>>  $sources
     */
    private function applySources(PreDpcProposal $proposal, Department $department, array $sources): void
    {
        $proposal->promotionSources()->delete();

        $seen = [];
        $order = 0;

        foreach ($sources as $source) {
            $postId = isset($source['promotion_from_post_id']) ? (int) $source['promotion_from_post_id'] : null;

            $attributes = $this->postAttributes->resolve($department->id, $postId);

            if ($attributes === null) {
                throw new WorkflowException(
                    'Every Promotion From Post must be a live post of this department. '
                    .'Register it in the Department Post master first.'
                );
            }

            if ($attributes->post->id === $proposal->promotion_to_post_id) {
                throw new WorkflowException('A post cannot be promoted into itself.');
            }

            if (in_array($attributes->post->id, $seen, true)) {
                throw new WorkflowException(sprintf(
                    '%s is named twice. A post appears once in an order, with one set of terms.',
                    $attributes->post->name,
                ));
            }

            $seen[] = $attributes->post->id;

            $created = $proposal->promotionSources()->create([
                'promotion_from_post_id' => $attributes->post->id,
                'promotion_percentage' => $source['promotion_percentage'] ?? 0,
                'min_experience_years' => $source['min_experience_years'] ?? 0,
                'experience_unit' => $source['experience_unit'] ?? ExperienceUnit::Years->value,

                // Copied from the feeder's own mapping, never posted: a signed order
                // states the qualifying service it sanctioned, and a master edited
                // later must not change what it said.
                'promotion_duration_years' => $attributes->promotionDurationYears,

                'sort_order' => $order++,
            ]);

            // Item 11: several acceptable qualifications, in the order entered.
            $created->qualifications()->attach(
                collect($this->qualificationIds($source))
                    ->mapWithKeys(fn (int $id, int $index): array => [$id => ['sort_order' => $index]])
                    ->all(),
            );
        }

        if ($seen === []) {
            throw new WorkflowException(
                'An order must name at least one Promotion From Post — the post or posts officers are promoted from.'
            );
        }
    }

    /**
     * Retire the exercise's currently active Pre-DPC (item 9).
     *
     * **Deactivated, never deleted** — the register says so in terms, and the
     * superseded order is what a tribunal reads to see what was sanctioned at the
     * time. Read under a write lock for the same reason as everything else here:
     * two approvals for one exercise arriving together would otherwise both pass
     * and the second would die on the unique index.
     */
    private function supersedeIncumbent(PreDpcProposal $proposal): void
    {
        $incumbent = PreDpcProposal::query()
            ->where($proposal->exerciseKey())
            ->whereNotNull('active_flag')
            ->whereKeyNot($proposal->id)
            ->lockForUpdate()
            ->first();

        $incumbent?->forceFill(['active_flag' => null])->save();
    }

    /**
     * The next version *within this exercise*.
     *
     * Per exercise rather than per department since 2026-08-24: two exercises in
     * one department are unrelated, and sharing a counter made "version 5" a
     * statement about the department's paperwork rather than about this promotion.
     *
     * @param  array<string, mixed>  $exercise
     */
    private function nextVersion(int $departmentId, array $exercise): int
    {
        // The Promotion From Post left the exercise key on 2026-08-25: an order may
        // name several, so it cannot identify the exercise.
        return (int) PreDpcProposal::query()
            ->where('department_id', $departmentId)
            ->where('promotion_to_post_id', $exercise['promotion_to_post_id'] ?? null)
            ->where('dpc_year', $exercise['dpc_year'] ?? null)
            ->max('version') + 1;
    }

    /**
     * Whether the Pre-DPC is complete enough to be allotted and submitted
     * (2026-10-28): the Department Admin assigns its AO and AH only then.
     *
     * @throws WorkflowException naming what is missing
     */
    public function assertComplete(PreDpcProposal $proposal): void
    {
        $this->guardComplete($proposal);
    }

    private function guardComplete(PreDpcProposal $proposal): void
    {
        if ($proposal->promotion_to_post_id === null) {
            throw new WorkflowException('Choose the Promotion To Post before submitting.');
        }

        if ($proposal->promotionSources()->count() === 0) {
            throw new WorkflowException(
                'Name at least one Promotion From Post before submitting — the post or posts officers are promoted from.'
            );
        }

        if ($proposal->dpc_year === null) {
            throw new WorkflowException('The DPC Year is required before submitting.');
        }

        if (($proposal->government_order_number === null) || ($proposal->effective_from === null)) {
            throw new WorkflowException('Government Order number and effective date are required before submitting.');
        }
    }

    /**
     * Run a signed transition; on a failed or unverified eSign, record a security
     * event (after the rollback, so it survives) and refuse (2026-10-27).
     */
    private function signed(User $actor, PreDpcProposal $proposal, string $action, callable $transition): void
    {
        try {
            $transition();
        } catch (SignatureVerificationFailed $e) {
            app(SecurityLog::class)->record('esign.'.$e->reason, $actor, context: ['subject' => 'pre_dpc', 'id' => $proposal->id, 'action' => $action]);
            app(SignatureAttemptLog::class)->record($actor, $proposal, $action, $e->reason, $e->assertedName);

            throw new WorkflowException($e->getMessage());
        }
    }

    /**
     * Re-checked under the proposal's lock: a reassignment that landed after the
     * screen was opened takes effect before anything is signed.
     */
    private function assertCurrentOfficer(User $actor, PreDpcProposal $proposal, Role $role): void
    {
        if (! $this->allotments->isAllotted($actor, $proposal, $role)) {
            throw new WorkflowException((string) __('esign.errors.not_current_officer'));
        }
    }

    /**
     * Production must never sign a legally significant approval with the mock
     * provider (architect ruling, 2026-08-13). Keyed on the feature flag,
     * which is the single switch deciding which provider is bound — checking
     * anything else could drift out of step with what actually signs.
     */
    private function assertSignatureAvailable(): void
    {
        if (app()->environment('production') && Feature::CscDigiSign->disabled()) {
            throw new WorkflowException(
                'Digital signing is unavailable: CSC DigiSign is not enabled on this environment, and a Pre-DPC '
                .'proposal may not be approved without a legally valid digital signature. Contact the system administrator.'
            );
        }
    }

    private function renderDocument(PreDpcProposal $proposal): string
    {
        $proposal->loadMissing(
            'promotionToPost',
            'payScale',
            'promotionSources.promotionFromPost.payScale',
            'promotionSources.qualifications',
        );

        $lines = [
            "PRE-DPC PROPOSAL {$proposal->pre_dpc_number} (v{$proposal->version})",
            "Department #{$proposal->department_id} — DPC Year {$proposal->dpc_year}",
            "GO: {$proposal->government_order_number}",
            "Promotion To Post: {$proposal->promotionToPost?->name} (Class {$proposal->class_level?->value})",
            'Pay Scale: '.($proposal->payScale === null ? '—' : $proposal->payScale->label),
            "Direct {$proposal->direct_recruitment_pct}% / Promotion {$proposal->promotion_recruitment_pct}%",
            '',
            'Promotion From Posts:',
        ];

        /*
         | Each feeder on its own line, with **its own** Pay Scale and Class.
         |
         | The signed order has to say what an officer of that post is, because that
         | is what eligibility is judged on — not the scale of the post above, which
         | is what this document printed until 2026-08-25.
         */
        foreach ($proposal->promotionSources as $source) {
            $post = $source->promotionFromPost;

            $lines[] = sprintf(
                '  - %s (Class %s, Pay Scale %s) — %s%% share, minimum experience %s %s, qualification %s',
                $post->name,
                $post->class_level->value,
                $post->payScale === null ? '—' : $post->payScale->label,
                $source->promotion_percentage,
                $source->min_experience_years,
                $source->experience_unit->value,
                $source->qualificationLabel() ?? '—',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The qualifications a posted promotion-source row accepts (item 11).
     *
     * De-duplicated and re-indexed, because
     * `unique(pre_dpc_promotion_source_id, qualification_id)` refuses a repeat
     * rather than ignoring it — and an officer ticking the same degree twice on one
     * feeder means one degree, not an error.
     *
     * @param  array<string, mixed>  $source
     * @return list<int>
     */
    private function qualificationIds(array $source): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, (array) ($source['qualification_ids'] ?? [])),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * Project the approved exercise into the Recruitment Rule master.
     *
     * **The rule is composed from the order's own Promotion From Posts**, which is
     * the whole reason a multi-feeder post
     * survived the reshape. Deputy Director fed from Assistant Director and from
     * Research Officer is two Pre-DPCs; the rule for Deputy Director names both
     * feeders, with the share each exercise sanctioned. Projecting only the
     * proposal just approved would create a rule that promotes from one post and
     * silently withdraw the other.
     *
     * A new rule *version* every time, never an edit: a recruitment rule is
     * versioned and never overwritten (architect directive), so the rule in force
     * when a past DPC ran is still readable.
     */
    private function projectToMasters(PreDpcProposal $proposal): void
    {
        /*
         | The order's own sources, and only those.
         |
         | This used to gather sibling orders for the same To Post and compose the
         | rule from all of them, because one order could name only one feeder and a
         | multi-feeder rule therefore had to be assembled from several. Now that an
         | order names every feeder itself — as it did before the change register of
         | 2026-08-24 — there is one order per post per year and nothing to gather.
         */
        $feeders = $proposal->promotionSources
            ->map(static fn (PreDpcPromotionSource $source): array => [
                'promotion_from_post_id' => $source->promotion_from_post_id,
                'promotion_percentage' => $source->promotion_percentage,
                'min_experience_years' => $source->min_experience_years,
                // The rule's own key. `experience_unit` here was read by nothing, so
                // an order in months was projected as years.
                'min_experience_unit' => $source->experience_unit->value,
                'qualification_ids' => $source->qualifications->pluck('id')->all(),
                'min_qualification' => $source->qualificationLabel(),
            ])
            ->values()
            ->all();

        // The post is a master row already — the screen chose it from the master,
        // and `PostAttributeResolver` refused anything that was not a live post of
        // this department. Nothing is created or matched by name here, which is
        // what closed TD-25 for good rather than for the common case.
        $post = DepartmentPost::query()->findOrFail($proposal->promotion_to_post_id);

        $this->rules->create([
            'post_id' => $post->id,
            'government_order_number' => (string) $proposal->government_order_number,
            'direct_recruitment_pct' => $proposal->direct_recruitment_pct,
            'promotion_recruitment_pct' => $proposal->promotion_recruitment_pct,
            'effective_from' => $proposal->effective_from,
            'status' => MasterStatus::Active->value,
        ], $feeders, null);
    }

    /**
     * Re-fetch the row under a write lock, inside the caller's transaction.
     *
     * **TD-5, closed.** Every transition above used to read `status` from the
     * instance the controller had already loaded, then write based on it. Two
     * simultaneous submits of the same proposal could therefore both pass
     * `assertAllowed()` against the same `draft`, and both write — producing two
     * transitions out of one state, two ledger rows, and on `approve` **two
     * signatures on one proposal**.
     *
     * The lock is taken *first* inside the transaction and the state is read from
     * the locked row — taking it after reading the status would leave the race
     * exactly where it was.
     */
    private function lock(PreDpcProposal $proposal): PreDpcProposal
    {
        return PreDpcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
    }
}
