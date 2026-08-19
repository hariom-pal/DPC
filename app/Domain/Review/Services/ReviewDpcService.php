<?php

declare(strict_types=1);

namespace App\Domain\Review\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Master\Models\DpcType;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Review\Enums\ChainRole;
use App\Domain\Review\Models\DpcProposalReviewReason;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Review DPC — creation and the correction chain (PPT S35–S38) — M8 Phase 1.
 *
 * S35 says the Review process is *identical* to a Fresh DPC, so this service
 * is deliberately thin: it establishes the **origin**, the **chain**, and the
 * **starting data**, and then gets out of the way. From creation onward a
 * Review is an ordinary M4 proposal edited through M4's existing screens,
 * services and validation.
 *
 * **Nothing here writes to the original.** A Review supersedes its original
 * (AQ-9), but the relationship lives in `dpc_review_links` rather than as a
 * column on the frozen `dpc_proposals`, and the original's own rows are read
 * once at creation and never touched again.
 */
class ReviewDpcService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * S37 — raise a Review of a concluded DPC.
     *
     * The Review's sections are **copied** from the original as new rows
     * (S38 is titled *Correction in Tabulation Chart*, not *Enter*). Making a
     * department retype ten officers to fix one CR entry would guarantee fresh
     * transcription errors while fixing an old one.
     *
     * @param  array<int, array{review_reason_id:int, detail?:string|null}>  $reasons
     */
    public function create(
        DpcProposal $original,
        User $actor,
        Carbon $proposalDate,
        array $reasons,
    ): DpcProposal {
        $this->assertReviewable($original);

        if ($reasons === []) {
            throw new WorkflowException('State at least one reason for the Review.');
        }

        return DB::transaction(function () use ($original, $actor, $proposalDate, $reasons): DpcProposal {
            // Re-read under a lock: two officers raising a Review of the same
            // DPC at the same instant would otherwise both pass the check
            // above. The unique index on `original_dpc_proposal_id` is the
            // backstop, but failing here gives a message rather than a
            // constraint violation.
            $locked = DpcProposal::query()->lockForUpdate()->findOrFail($original->id);
            $this->assertReviewable($locked);

            $review = $this->copyProposal($locked, $actor, $proposalDate);

            foreach ($reasons as $reason) {
                DpcProposalReviewReason::query()->create([
                    'dpc_proposal_id' => $review->id,
                    'review_reason_id' => $reason['review_reason_id'],
                    'detail' => $reason['detail'] ?? null,
                ]);
            }

            // The chain is inherited from the link that makes the original
            // *itself* a Review, if it is one. Looking for a link where the
            // original is the reviewed party would always find nothing —
            // `assertReviewable()` has just guaranteed that.
            $existing = $this->linkForReview($locked);

            $link = DpcReviewLink::query()->create([
                'review_dpc_proposal_id' => $review->id,
                'original_dpc_proposal_id' => $locked->id,
                // Directive 1 — which version of the original this corrects.
                'original_proposal_version' => $this->concludedProceeding($locked)?->proposal_version,
                // A Fresh DPC starts a chain; a Review of a Review continues
                // the one it is already in.
                'chain_uuid' => $existing === null ? (string) Str::uuid() : $existing->chain_uuid,
                'review_number' => ($existing === null ? 0 : $existing->review_number) + 1,
                // Directive 2 — stored, never derived by walking the chain.
                // The original counts as depth 1, so the first Review is 2.
                'chain_depth' => ($existing === null ? 1 : $existing->chain_depth) + 1,
                'effect' => 'supersedes',
                'established_by' => $actor->id,
                'established_by_name' => $actor->name,
                'established_at' => now(),
            ]);

            $this->timeline->record(
                'dpc_review.created',
                $review,
                'Review DPC raised against '.($locked->dpc_code ?? 'the original proposal'),
                array_merge($link->toTimelineProperties(), [
                    'original_dpc_proposal_uuid' => (string) $locked->uuid,
                    'original_dpc_code' => $locked->dpc_code,
                    'original_proposal_date' => $locked->proposal_date->toDateString(),
                    'review_dpc_proposal_uuid' => (string) $review->uuid,
                    'review_proposal_date' => $review->proposal_date->toDateString(),
                    'reasons' => $this->reasonCodes($review),
                ]),
                $actor,
            );

            // The same event on the original's own thread, so the chain is
            // visible from either end of the relationship rather than only
            // from the correction that caused it.
            $this->timeline->record(
                'dpc_review.superseded_by',
                $locked,
                'A Review DPC has been raised against this proposal',
                array_merge($link->toTimelineProperties(), [
                    'review_dpc_proposal_uuid' => (string) $review->uuid,
                    'review_proposal_date' => $review->proposal_date->toDateString(),
                    'reasons' => $this->reasonCodes($review),
                ]),
                $actor,
            );

            return $review->fresh();
        });
    }

    /**
     * The DPCs a department may raise a Review against.
     *
     * A Review corrects a **concluded** DPC — one whose result the Commission
     * approved — and, per AQ-14, only the **latest** in a chain. An earlier
     * Review already corrected by a later one is not offered.
     *
     * @return EloquentCollection<int, DpcProposal>
     */
    public function eligibleOriginals(int $departmentId): EloquentCollection
    {
        $alreadyReviewed = DpcReviewLink::query()->pluck('original_dpc_proposal_id');

        return DpcProposal::query()
            ->where('department_id', $departmentId)
            ->where('status', DpcProposalStatus::Approved->value)
            ->whereNotIn('id', $alreadyReviewed)
            ->whereIn('id', $this->concludedProposalIds())
            ->with(['postFromCadre', 'postToCadre'])
            ->orderByDesc('proposal_date')
            ->get();
    }

    /**
     * Every DPC in a correction chain, oldest first — the original followed by
     * each Review in order (architect Directive 4).
     *
     * Returned as a plain list rather than a Collection: the shape is a
     * tagged array, and `Collection`'s value template is invariant, so a
     * precisely-typed collection cannot satisfy its own declared return type.
     *
     * Each node carries its **role** (architect Directive 4): the original,
     * the active Review, or a superseded one. The role is derived from
     * position — the newest Review is operative — not from whether
     * supersession has legally taken effect, which is Phase 3's concern.
     *
     * @return list<array{review_number:int, chain_depth:int, proposal:DpcProposal|null, role:ChainRole, is_original:bool, link:DpcReviewLink|null}>
     */
    public function chain(DpcProposal $proposal): array
    {
        $chainUuid = $this->chainUuidFor($proposal);

        if ($chainUuid === null) {
            return [];
        }

        $links = DpcReviewLink::query()
            ->inChain($chainUuid)
            ->with(['review.postToCadre', 'original.postToCadre'])
            ->get();

        if ($links->isEmpty()) {
            return [];
        }

        $entries = [[
            'review_number' => 0,
            'chain_depth' => 1,
            'proposal' => $links->first()->original,
            'role' => ChainRole::Original,
            'is_original' => true,
            'link' => null,
        ]];

        $last = $links->count() - 1;

        foreach ($links as $index => $link) {
            $entries[] = [
                'review_number' => $link->review_number,
                'chain_depth' => $link->chain_depth,
                'proposal' => $link->review,
                // Exactly one node in a chain is operative: the newest Review.
                'role' => $index === $last ? ChainRole::ActiveReview : ChainRole::SupersededReview,
                'is_original' => false,
                'link' => $link,
            ];
        }

        return $entries;
    }

    /**
     * The chain a proposal belongs to, whether it is the original or a Review.
     */
    public function chainUuidFor(DpcProposal $proposal): ?string
    {
        return DpcReviewLink::query()
            ->where('review_dpc_proposal_id', $proposal->id)
            ->orWhere('original_dpc_proposal_id', $proposal->id)
            ->value('chain_uuid');
    }

    /**
     * The link that makes this proposal a Review, or null if it is not one.
     */
    public function linkForReview(DpcProposal $proposal): ?DpcReviewLink
    {
        return DpcReviewLink::query()
            ->where('review_dpc_proposal_id', $proposal->id)
            ->first();
    }

    /**
     * This proposal's role in its chain, or null if it is not in one
     * (architect Directive 4).
     */
    public function roleOf(DpcProposal $proposal): ?ChainRole
    {
        foreach ($this->chain($proposal) as $entry) {
            if ($entry['proposal']?->id === $proposal->id) {
                return $entry['role'];
            }
        }

        return null;
    }

    /**
     * The concluded proceeding that produced the original's result — the
     * source of the version this Review corrects.
     */
    public function concludedProceeding(DpcProposal $proposal): ?DpcProceeding
    {
        return DpcProceeding::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('status', DpcProceedingStatus::ResultApproved->value)
            ->latest('opened_at')
            ->first();
    }

    /**
     * The calculation the original's result was approved on — the source of the
     * rules a Review must re-run under (architect ruling AQ-11).
     */
    public function originalCalculationRun(DpcProposal $review): ?DpcCalculationRun
    {
        $link = $this->linkForReview($review);

        if ($link === null || $link->original === null) {
            return null;
        }

        $proceeding = $this->concludedProceeding($link->original);

        return $proceeding?->finalCalculationRun;
    }

    /**
     * The link by which this DPC has been superseded — a Review whose result
     * has been approved — or null if it still governs.
     *
     * Only an *effective* supersession counts. A Review that has been raised
     * but not approved leaves the original standing, and its documents must
     * not be marked otherwise.
     */
    public function supersedingLinkFor(?DpcProposal $proposal): ?DpcReviewLink
    {
        if ($proposal === null) {
            return null;
        }

        return DpcReviewLink::query()
            ->where('original_dpc_proposal_id', $proposal->id)
            ->whereNotNull('superseded_at')
            ->with('review')
            ->first();
    }

    public function isSuperseded(DpcProposal $proposal): bool
    {
        return $this->supersedingLinkFor($proposal) !== null;
    }

    public function isReview(DpcProposal $proposal): bool
    {
        return $this->linkForReview($proposal) !== null;
    }

    /**
     * The reasons a Review was raised on.
     *
     * @return EloquentCollection<int, DpcProposalReviewReason>
     */
    public function reasons(DpcProposal $proposal): EloquentCollection
    {
        return DpcProposalReviewReason::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->with('reason')
            ->get();
    }

    /**
     * The chain's history, read **only** from timeline records (AD-6).
     *
     * @return Collection<int, ActivityTimeline>
     */
    public function historyFromTimeline(string $chainUuid): Collection
    {
        return ActivityTimeline::query()
            ->where('event', 'like', 'dpc_review.%')
            ->where('properties->chain_uuid', $chainUuid)
            ->orderBy('occurred_at')
            ->orderBy('uuid')
            ->get();
    }

    // ------------------------------------------------------------- internals

    /**
     * A Review may be raised only against a **concluded** DPC that is not
     * already reviewed (AQ-14).
     */
    private function assertReviewable(DpcProposal $original): void
    {
        if ($original->status !== DpcProposalStatus::Approved) {
            throw new WorkflowException(
                'Only a DPC the department has approved and forwarded can be reviewed.'
            );
        }

        if (! in_array($original->id, $this->concludedProposalIds(), true)) {
            throw new WorkflowException(
                'This DPC has not been conducted yet. A Review corrects a DPC whose result the Commission '
                .'has approved; a DPC still in progress is corrected by returning it.'
            );
        }

        if ($this->linkFor($original) !== null) {
            // AQ-14: only the latest in a chain may itself be reviewed.
            throw new WorkflowException(
                'This DPC has already been reviewed. Raise the Review against the latest DPC in the chain.'
            );
        }
    }

    private function linkFor(DpcProposal $original): ?DpcReviewLink
    {
        return DpcReviewLink::query()
            ->where('original_dpc_proposal_id', $original->id)
            ->first();
    }

    /**
     * Proposals whose result the Commission approved.
     *
     * @return list<int>
     */
    private function concludedProposalIds(): array
    {
        return DpcProceeding::query()
            ->where('status', DpcProceedingStatus::ResultApproved->value)
            ->pluck('dpc_proposal_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Copy the original's sections onto a new proposal as **new rows**.
     *
     * Copying rather than referencing gives three things the reference model
     * cannot: the original is never read at edit time so it cannot be mutated
     * by accident, the Review is self-contained like every other evidence
     * record (AD-4), and *what changed* is answerable by comparing two
     * snapshots — which is exactly what a tribunal asks of a Review.
     *
     * Deliberately **not** copied: `dpc_code` (a Review earns its own DPC ID at
     * approval), approval and signature fields, and anything from the
     * original's proceeding. A Review inherits *data*, never *decisions*.
     *
     * **`legal_stay` is also not copied, and that is a decision rather than an
     * omission.** It is the department's declaration that no court stay is in
     * force *now*; carrying a years-old declaration forward would restate a
     * legal fact nobody has re-checked. The AO re-declares it on S14, as they
     * would on a Fresh DPC. Raised for architect confirmation because design §7
     * does not list it among the exclusions.
     */
    private function copyProposal(DpcProposal $original, User $actor, Carbon $proposalDate): DpcProposal
    {
        $original->loadMissing([
            'committeeMembers', 'vacancies', 'workingOfficers', 'seniorityEntries',
            'evaluationRows.crGrades', 'evaluationRows.loyaltyFlags', 'evaluationRows.propertyFlags',
        ]);

        $review = DpcProposal::query()->create([
            'department_id' => $original->department_id,
            'dpc_type_id' => DpcType::query()->where('code', 'review')->firstOrFail()->id,
            'post_to_cadre_id' => $original->post_to_cadre_id,
            'post_from_cadre_id' => $original->post_from_cadre_id,
            'original_dpc_proposal_id' => $original->id,
            'legal_stay' => false,
            // S16's sanctioned post count belongs to the section whose rows are
            // copied below, and M4's completeness gate refuses to submit a
            // proposal without it. Leaving it null made every Review start
            // incomplete — the department would have had to re-enter a number
            // the original already carried, which is exactly the copy drift
            // design risk R3 warns about.
            'sanctioned_post_count' => $original->sanctioned_post_count,
            'proposal_date' => $proposalDate->toDateString(),
            'status' => DpcProposalStatus::Draft->value,
            'prepared_by' => $actor->id,
        ]);

        // `created_by` is not fillable on the M4 model, so it is set by direct
        // update rather than through mass assignment. Strict attribute mode (AD-18)
        // turned what would have been a silently discarded value into an exception
        // here — which is the point of it.
        //
        // The UUID is no longer set here: `DpcProposal` mints its own since TD-19
        // was closed (2026-08-19). Overwriting it would replace a UUID the model had
        // already issued, and anything that read the identifier between the two
        // writes would have read one that no longer exists.
        DB::table('dpc_proposals')->where('id', $review->id)->update([
            'created_by' => $actor->id,
        ]);

        foreach ($original->committeeMembers as $member) {
            $review->committeeMembers()->create($this->attributesOf($member));
        }

        foreach ($original->vacancies as $vacancy) {
            $review->vacancies()->create($this->attributesOf($vacancy));
        }

        foreach ($original->workingOfficers as $officer) {
            $review->workingOfficers()->create($this->attributesOf($officer));
        }

        foreach ($original->seniorityEntries as $entry) {
            $review->seniorityEntries()->create($this->attributesOf($entry));
        }

        foreach ($original->evaluationRows as $row) {
            $copy = $review->evaluationRows()->create($this->attributesOf($row));

            foreach ($row->crGrades as $grade) {
                $copy->crGrades()->create($this->attributesOf($grade, ['dpc_evaluation_row_id']));
            }

            foreach ($row->loyaltyFlags as $flag) {
                $copy->loyaltyFlags()->create($this->attributesOf($flag, ['dpc_evaluation_row_id']));
            }

            foreach ($row->propertyFlags as $flag) {
                $copy->propertyFlags()->create($this->attributesOf($flag, ['dpc_evaluation_row_id']));
            }
        }

        return $review->fresh();
    }

    /**
     * A row's own attributes, minus identity and ownership.
     *
     * Taken from `getAttributes()` rather than a hand-written field list: a
     * column added to a section table by a later migration would otherwise be
     * silently dropped from every copy, and the Review would start from data
     * the department never submitted (design risk R3).
     *
     * @param  list<string>  $alsoExcept
     * @return array<string, mixed>
     */
    private function attributesOf(object $model, array $alsoExcept = []): array
    {
        /** @var Model $model */
        $except = array_merge(
            ['id', 'uuid', 'dpc_proposal_id', 'created_at', 'updated_at'],
            $alsoExcept,
        );

        return collect($model->getAttributes())
            ->except($except)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function reasonCodes(DpcProposal $review): array
    {
        return $this->reasons($review)
            ->map(fn (DpcProposalReviewReason $r): string => (string) $r->reason?->code)
            ->filter()
            ->values()
            ->all();
    }
}
