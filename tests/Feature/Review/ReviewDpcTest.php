<?php

declare(strict_types=1);

namespace Tests\Feature\Review;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\Review\Models\DpcProposalReviewReason;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\Review\Services\ReviewDpcService;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Models\Audit;
use RuntimeException;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * Review DPC — origin, chain and copy-on-create (PPT S35, S37–S38) —
 * M8 Phase 1.
 *
 * The milestone's single most important guarantee is tested first and stated
 * plainly: **editing a Review must never touch the original.** Everything else
 * — the chain, the numbering, the reasons — is bookkeeping around that.
 */
class ReviewDpcTest extends TestCase
{
    use BuildsProceedings;

    private User $authorizedOfficerTwo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProceeding();

        $this->officer(1, 'US1', $this->urCategoryId(),
            [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
        $this->officer(3, 'US3', $this->stCategoryId(),
            [2013 => 'ख', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);

        // A real second department, so the test exercises the department
        // mismatch rather than the "no department at all" authorisation path.
        $otherDepartment = Department::query()->create([
            'code' => 'FI', 'name' => 'Finance Department', 'status' => 'active',
        ]);
        $this->authorizedOfficerTwo = User::factory()->create([
            'name' => 'Other AO', 'department_id' => $otherDepartment->id,
        ]);
        $this->authorizedOfficerTwo->assignRole(Role::AuthorizedOfficer->value);
    }

    // ----------------------------------------------------------------- helpers

    private function reviews(): ReviewDpcService
    {
        return app(ReviewDpcService::class);
    }

    /** Conclude the DPC so it becomes reviewable. */
    private function concludeOriginal(): void
    {
        $this->calculate();
        app(ResultApprovalService::class)->approve(
            $this->proceeding->fresh(), $this->memberOne, request(), 2017,
        );
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{review_reason_id:int, detail?:string|null}>
     */
    private function reasons(array $codes = ['wrong_cr']): array
    {
        return collect($codes)
            ->map(fn (string $code): array => [
                'review_reason_id' => ReviewReason::query()->where('code', $code)->firstOrFail()->id,
                'detail' => 'Recorded at the sitting.',
            ])
            ->all();
    }

    private function raise(?DpcProposal $original = null, array $codes = ['wrong_cr']): DpcProposal
    {
        return $this->reviews()->create(
            ($original ?? $this->proposal)->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-08-20'),
            $this->reasons($codes),
        );
    }

    /** Take a Review through to a concluded result, so it can itself be reviewed. */
    private function concludeReview(DpcProposal $review): void
    {
        $review->forceFill([
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'HE202600002',
            'forwarded_at' => now(),
        ])->saveQuietly();

        $proceeding = DpcProceeding::query()->create([
            'dpc_proposal_id' => $review->id,
            'status' => DpcProceedingStatus::ResultApproved->value,
            'conducted_by' => $this->memberOne->id,
            'conducted_by_name' => $this->memberOne->name,
            'proposal_version' => str_repeat('c', 64),
            'opened_at' => now(),
        ]);

        $this->assertNotNull($proceeding->id);
    }

    // ------------------------------------------------------------- the guarantee

    public function test_editing_a_review_never_touches_the_original(): void
    {
        // The single most important test in this milestone.
        $this->concludeOriginal();
        $review = $this->raise();

        $originalRow = $this->proposal->fresh()->evaluationRows->firstWhere('sn', 3);
        $before = $originalRow->crGrades->firstWhere('year', 2013)->grade;

        $reviewRow = $review->evaluationRows->firstWhere('sn', 3);
        $reviewRow->crGrades()->where('year', 2013)->update(['grade' => 'क+']);

        $this->assertSame($before, $this->proposal->fresh()->evaluationRows
            ->firstWhere('sn', 3)->crGrades->firstWhere('year', 2013)->grade);
        $this->assertSame('क+', $review->fresh()->evaluationRows
            ->firstWhere('sn', 3)->crGrades->firstWhere('year', 2013)->grade);
    }

    public function test_the_review_owns_its_own_rows(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();

        $originalIds = $this->proposal->fresh()->evaluationRows->pluck('id');
        $reviewIds = $review->evaluationRows->pluck('id');

        $this->assertCount(2, $reviewIds);
        $this->assertEmpty($originalIds->intersect($reviewIds));
    }

    // --------------------------------------------------------- copy on create

    public function test_the_chart_is_copied_field_by_field(): void
    {
        // S38 is "Correction in Tabulation Chart", not "Enter". Retyping ten
        // officers to fix one entry would create fresh errors while fixing an
        // old one.
        $this->concludeOriginal();
        $review = $this->raise();

        foreach ($this->proposal->fresh()->evaluationRows as $source) {
            $copy = $review->evaluationRows->firstWhere('sn', $source->sn);

            $this->assertNotNull($copy);
            $this->assertSame($source->name, $copy->name);
            $this->assertSame($source->dob->toDateString(), $copy->dob->toDateString());
            $this->assertSame($source->qualification, $copy->qualification);
            $this->assertSame($source->reservation_category_id, $copy->reservation_category_id);
            $this->assertSame(
                $source->crGrades->pluck('grade', 'year')->sortKeys()->all(),
                $copy->crGrades->pluck('grade', 'year')->sortKeys()->all(),
            );
            $this->assertSame(
                $source->propertyFlags->pluck('flag', 'year')->sortKeys()->all(),
                $copy->propertyFlags->pluck('flag', 'year')->sortKeys()->all(),
            );
        }
    }

    public function test_vacancies_are_copied(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();

        $this->assertSame(
            $this->proposal->fresh()->vacancies->count(),
            $review->vacancies->count(),
        );
    }

    public function test_the_sanctioned_post_count_is_copied_so_the_review_starts_complete(): void
    {
        // Design §7 — a Review starts from the original's data — read against
        // M4's own submission gate rather than against the section tables.
        //
        // The sanctioned post count belongs to S16, whose rows *are* copied,
        // and `DpcProposalCompletenessService` refuses to submit a proposal
        // whose count is null. Dropping it in the copy therefore left every
        // Review unsubmittable until the department re-typed a number the
        // original already carried — copy drift, design risk R3, and invisible
        // to a test that only compares section rows.
        $this->proposal->forceFill(['sanctioned_post_count' => 4])->saveQuietly();

        $this->concludeOriginal();
        $review = $this->raise();

        $this->assertSame(4, $review->sanctioned_post_count);
        $this->assertNotContains(
            'Working Officers & Vacancies (S16)',
            app(DpcProposalCompletenessService::class)->missingSections($review->fresh()),
        );
    }

    public function test_the_review_is_a_draft_of_type_review_with_no_dpc_id(): void
    {
        // A Review inherits data, never decisions.
        $this->concludeOriginal();
        $review = $this->raise();

        $this->assertSame(DpcProposalStatus::Draft, $review->status);
        $this->assertSame(
            DpcType::query()->where('code', 'review')->firstOrFail()->id,
            $review->dpc_type_id,
        );
        $this->assertNull($review->dpc_code);
        $this->assertNull($review->approved_at);
        $this->assertNull($review->signature_id);
        $this->assertSame($this->proposal->id, $review->original_dpc_proposal_id);
        $this->assertNotNull($review->uuid);
    }

    // ------------------------------------------------------------------ chain

    public function test_a_review_gets_a_chain_uuid_and_review_number(): void
    {
        // Architect Directives 1 and 2.
        $this->concludeOriginal();
        $review = $this->raise();

        $link = DpcReviewLink::query()->firstOrFail();

        $this->assertSame($review->id, $link->review_dpc_proposal_id);
        $this->assertSame($this->proposal->id, $link->original_dpc_proposal_id);
        $this->assertSame(1, $link->review_number);
        $this->assertNotEmpty($link->chain_uuid);
        $this->assertSame('supersedes', $link->effect);
        $this->assertSame('Authorized Officer', $link->established_by_name);
    }

    public function test_the_link_is_bidirectional(): void
    {
        // AQ-9: permanent references in both directions, from one row.
        $this->concludeOriginal();
        $review = $this->raise();

        $link = DpcReviewLink::query()->firstOrFail();

        $this->assertSame($review->id, $link->review->id);
        $this->assertSame($this->proposal->id, $link->original->id);
    }

    public function test_supersession_does_not_take_effect_until_the_review_is_approved(): void
    {
        // Raising a Review must not invalidate the original: a Review that is
        // cancelled or never approved leaves the original standing.
        $this->concludeOriginal();
        $this->raise();

        $this->assertNull(DpcReviewLink::query()->firstOrFail()->superseded_at);
        $this->assertFalse(DpcReviewLink::query()->firstOrFail()->hasTakenEffect());
    }

    public function test_a_review_of_a_review_continues_the_same_chain(): void
    {
        $this->concludeOriginal();
        $first = $this->raise();
        $this->concludeReview($first);

        $second = $this->raise($first);

        $links = DpcReviewLink::query()->orderBy('review_number')->get();

        $this->assertCount(2, $links);
        $this->assertSame($links[0]->chain_uuid, $links[1]->chain_uuid);
        $this->assertSame(1, $links[0]->review_number);
        $this->assertSame(2, $links[1]->review_number);
        $this->assertSame($first->id, $links[1]->original_dpc_proposal_id);
        $this->assertSame($second->id, $links[1]->review_dpc_proposal_id);
    }

    public function test_the_chain_reads_original_then_reviews_in_order(): void
    {
        // Architect Directive 4.
        $this->concludeOriginal();
        $first = $this->raise();
        $this->concludeReview($first);
        $second = $this->raise($first);

        $chain = $this->reviews()->chain($second->fresh());

        $this->assertCount(3, $chain);
        $this->assertTrue($chain[0]['is_original']);
        $this->assertSame($this->proposal->id, $chain[0]['proposal']->id);
        $this->assertSame(1, $chain[1]['review_number']);
        $this->assertSame(2, $chain[2]['review_number']);
    }

    public function test_the_chain_is_readable_from_the_original_too(): void
    {
        $this->concludeOriginal();
        $this->raise();

        $this->assertCount(2, $this->reviews()->chain($this->proposal->fresh()));
    }

    // ------------------------------------------------------------- validation

    public function test_only_the_latest_in_a_chain_may_be_reviewed(): void
    {
        // AQ-14.
        $this->concludeOriginal();
        $first = $this->raise();
        $this->concludeReview($first);
        $this->raise($first);

        $this->expectException(WorkflowException::class);
        $this->raise($first);
    }

    public function test_an_original_cannot_be_reviewed_twice(): void
    {
        $this->concludeOriginal();
        $this->raise();

        $this->expectException(WorkflowException::class);
        $this->raise();
    }

    public function test_a_dpc_that_has_not_been_conducted_cannot_be_reviewed(): void
    {
        // A DPC still in progress is corrected by returning it, not reviewed.
        $this->expectException(WorkflowException::class);
        $this->raise();
    }

    public function test_an_unapproved_proposal_cannot_be_reviewed(): void
    {
        $this->concludeOriginal();
        $this->proposal->forceFill(['status' => DpcProposalStatus::Draft->value])->saveQuietly();

        $this->expectException(WorkflowException::class);
        $this->raise();
    }

    public function test_at_least_one_reason_is_required(): void
    {
        $this->concludeOriginal();

        $this->expectException(WorkflowException::class);
        $this->reviews()->create(
            $this->proposal->fresh(), $this->authorizedOfficer, Carbon::parse('2026-08-20'), [],
        );
    }

    public function test_several_reasons_may_be_recorded_together(): void
    {
        // S37 renders them as checkboxes — a name omitted from the seniority
        // list is frequently also a roster-point error.
        $this->concludeOriginal();
        $review = $this->raise(codes: ['name_omitted', 'wrong_roster', 'wrong_cr']);

        $this->assertCount(3, DpcProposalReviewReason::query()
            ->where('dpc_proposal_id', $review->id)->get());
    }

    // ---------------------------------------------------- eligible originals

    public function test_only_concluded_unreviewed_dpcs_are_offered(): void
    {
        $this->assertCount(0, $this->reviews()->eligibleOriginals($this->department->id));

        $this->concludeOriginal();
        $this->assertCount(1, $this->reviews()->eligibleOriginals($this->department->id));

        $this->raise();
        $this->assertCount(0, $this->reviews()->eligibleOriginals($this->department->id));
    }

    public function test_another_departments_dpc_is_not_offered(): void
    {
        $this->concludeOriginal();

        $this->assertCount(0, $this->reviews()->eligibleOriginals($this->department->id + 999));
    }

    // -------------------------------------------------------- timeline & audit

    public function test_creation_is_recorded_on_both_proposals_timelines(): void
    {
        // The chain must be visible from either end of the relationship, not
        // only from the correction that caused it.
        $this->concludeOriginal();
        $review = $this->raise();

        $onReview = ActivityTimeline::query()->where('event', 'dpc_review.created')->firstOrFail();
        $onOriginal = ActivityTimeline::query()->where('event', 'dpc_review.superseded_by')->firstOrFail();

        $this->assertSame($review->id, $onReview->subject_id);
        $this->assertSame($this->proposal->id, $onOriginal->subject_id);
        $this->assertSame(
            $onReview->properties['chain_uuid'],
            $onOriginal->properties['chain_uuid'],
        );
    }

    public function test_the_timeline_entry_carries_everything_needed_to_replay_it(): void
    {
        $this->concludeOriginal();
        $this->raise(codes: ['wrong_cr', 'tribal_service']);

        $p = ActivityTimeline::query()->where('event', 'dpc_review.created')->firstOrFail()->properties;

        $this->assertSame(1, $p['review_number']);
        $this->assertNotEmpty($p['chain_uuid']);
        $this->assertNotEmpty($p['review_link_uuid']);
        $this->assertSame('HE202600001', $p['original_dpc_code']);
        $this->assertSame('2026-06-25', $p['original_proposal_date']);
        $this->assertSame('2026-08-20', $p['review_proposal_date']);
        $this->assertEqualsCanonicalizing(['wrong_cr', 'tribal_service'], $p['reasons']);
        $this->assertSame('Authorized Officer', $p['established_by_name']);
    }

    public function test_chain_history_is_read_from_the_timeline_alone(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();
        $chainUuid = $this->reviews()->chainUuidFor($review->fresh());

        $entries = $this->reviews()->historyFromTimeline((string) $chainUuid);

        $this->assertCount(2, $entries);
    }

    public function test_a_review_link_is_audited_and_tagged_with_the_chain(): void
    {
        $this->concludeOriginal();
        $this->raise();

        $audit = Audit::query()
            ->where('auditable_type', (new DpcReviewLink)->getMorphClass())->firstOrFail();

        $this->assertStringContainsString(
            DpcReviewLink::query()->firstOrFail()->chain_uuid,
            (string) $audit->tags,
        );
    }

    // ------------------------------------------------------------ immutability

    public function test_a_review_link_cannot_be_edited(): void
    {
        $this->concludeOriginal();
        $this->raise();

        $this->expectException(RuntimeException::class);
        DpcReviewLink::query()->firstOrFail()->update(['review_number' => 9]);
    }

    public function test_a_review_link_cannot_be_deleted(): void
    {
        $this->concludeOriginal();
        $this->raise();

        $this->expectException(RuntimeException::class);
        DpcReviewLink::query()->firstOrFail()->delete();
    }

    public function test_a_superseded_dpc_cannot_be_un_superseded(): void
    {
        $this->concludeOriginal();
        $this->raise();

        $link = DpcReviewLink::query()->firstOrFail();
        $link->update(['superseded_at' => now()]);

        $this->expectException(RuntimeException::class);
        $link->fresh()->update(['superseded_at' => null]);
    }

    // ---------------------------------------------------------------- screens

    public function test_the_review_creation_screen_lists_eligible_dpcs_and_reasons(): void
    {
        $this->concludeOriginal();

        $this->actingAs($this->authorizedOfficer)
            ->get(route('dpc-proposals.reviews.create'))
            ->assertOk()
            ->assertSee('Review DPC')
            ->assertSee('HE202600001')
            ->assertSee('Reason for Review DPC')
            // The grounds must be **named**, not coded. This screen asserted
            // only its own headings, so a label reading `name_omitted` passed
            // it — the ground is what the officer picks a Review on, and a
            // machine code is not a ground anyone can read.
            ->assertSee('Wrong CR entry')
            ->assertSee('Name omitted from seniority list')
            ->assertDontSee('name_omitted');
    }

    public function test_a_review_can_be_raised_through_the_screen(): void
    {
        $this->concludeOriginal();

        $this->actingAs($this->authorizedOfficer)
            ->post(route('dpc-proposals.reviews.store'), [
                'original_dpc_proposal_uuid' => $this->proposal->fresh()->uuid,
                'proposal_date' => '2026-08-20',
                'reasons' => [ReviewReason::query()->where('code', 'wrong_cr')->firstOrFail()->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DpcReviewLink::query()->count());
    }

    public function test_raising_a_review_without_a_reason_is_refused(): void
    {
        $this->concludeOriginal();

        $this->actingAs($this->authorizedOfficer)
            ->post(route('dpc-proposals.reviews.store'), [
                'original_dpc_proposal_uuid' => $this->proposal->fresh()->uuid,
                'proposal_date' => '2026-08-20',
            ])
            ->assertSessionHasErrors('reasons');

        $this->assertSame(0, DpcReviewLink::query()->count());
    }

    public function test_another_departments_dpc_cannot_be_reviewed(): void
    {
        $this->concludeOriginal();

        $this->actingAs($this->authorizedOfficerTwo)
            ->post(route('dpc-proposals.reviews.store'), [
                'original_dpc_proposal_uuid' => $this->proposal->fresh()->uuid,
                'proposal_date' => '2026-08-20',
                'reasons' => [ReviewReason::query()->where('code', 'wrong_cr')->firstOrFail()->id],
            ])
            ->assertSessionHasErrors('original_dpc_proposal_uuid');

        $this->assertSame(0, DpcReviewLink::query()->count());
    }

    public function test_the_review_banner_appears_on_the_reviews_own_screens(): void
    {
        // Architect Directive 3. A Review looks exactly like a Fresh DPC —
        // which is precisely why it needs saying.
        $this->concludeOriginal();
        $review = $this->raise();

        $this->actingAs($this->authorizedOfficer)
            ->get(route('dpc-proposals.show', $review))
            ->assertOk()
            ->assertSee('This is a Review DPC');
    }

    public function test_the_banner_does_not_appear_on_a_fresh_dpc(): void
    {
        $this->actingAs($this->authorizedOfficer)
            ->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertDontSee('This is a Review DPC');
    }

    public function test_the_chain_screen_renders(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();

        $this->actingAs($this->authorizedOfficer)
            ->get(route('dpc-proposals.reviews.chain', $review))
            ->assertOk()
            ->assertSee('Review Chain')
            ->assertSee('HE202600001');
    }

    /**
     * The banner offers the chain to the officer who can open it.
     *
     * Half of a pair — see the Commission case below.
     */
    public function test_the_banner_offers_the_chain_to_a_departmental_officer(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();

        $this->actingAs($this->authorizedOfficer)
            ->get(route('dpc-proposals.show', $review))
            ->assertOk()
            ->assertSee('This is a Review DPC')
            ->assertSee(route('dpc-proposals.reviews.chain', $review), escape: false);
    }

    /**
     * **The banner reaches the Commission's screens too, and must not offer them
     * a link they cannot follow.**
     *
     * The composer fires on any route that binds a proposal, and the Commission
     * side has four — `/cgpsc/allotments/{dpcProposal}/history` and the schedule
     * equivalents. A Chairman or Member conducting a Review therefore saw the
     * banner, pressed **Review Chain**, and got an access-denied page: the chain
     * lives under `dpc-proposals/*`, which middleware gates to the two
     * departmental roles.
     *
     * Found on 2026-08-22 by crawling the application as each role in a browser.
     * Nothing in the suite saw it, because every test that rendered the banner
     * rendered it as a departmental user — and because a link that is never
     * followed cannot fail.
     *
     * Asserted as a pair, in the house style: **the link is absent for the role
     * that cannot use it, and the route still refuses that role.** Asserting only
     * the absence would let a later change drop the backend check and stay green,
     * which is what makes hiding a button theatre rather than usability.
     */
    public function test_the_commission_is_not_offered_a_chain_it_cannot_open(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();

        $chainUrl = route('dpc-proposals.reviews.chain', $review);

        // A Commission screen that binds the proposal, so the banner renders.
        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.allotments.history', $review))
            ->assertOk()
            ->assertSee('This is a Review DPC')
            ->assertDontSee($chainUrl, escape: false);

        // And the route itself still refuses them.
        $this->actingAs($this->memberOne)->get($chainUrl)->assertForbidden();
    }

    /**
     * An officer of another department is offered nothing either — the role gate
     * would let them through, and the policy is what stops them.
     */
    public function test_an_officer_of_another_department_is_refused_the_chain(): void
    {
        $this->concludeOriginal();
        $review = $this->raise();

        $this->actingAs($this->authorizedOfficerTwo)
            ->get(route('dpc-proposals.reviews.chain', $review))
            ->assertForbidden();
    }
}
