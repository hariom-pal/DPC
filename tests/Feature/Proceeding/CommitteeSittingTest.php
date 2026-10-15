<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Proceeding\Models\DpcCandidateCall;
use App\Domain\Proceeding\Services\CommitteeSittingService;
use Illuminate\Support\Carbon;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * The sitting, from a committee member's side (business instruction, 2026-09-07).
 *
 * > *When all the members click the DPC that is being held, the whole candidate list
 * > should appear. Clicking a candidate shows the full record, and a Call button
 * > appears — the call activates only on the date and time the DPC is scheduled for.
 * > Records should be shown candidate by candidate, and once a candidate's DPC is
 * > done, mark it concluded.*
 *
 * **The authorisation is the point of most of what follows.** Every other Commission
 * screen is guarded on `role:chairman|member`, and a committee member holds neither —
 * item 7 forbids granting a Commission role for sitting on one DPC's committee. So
 * these screens are guarded by a *seat*, and the tests that matter most are the ones
 * asserting that a person with no role gets in and a person off the committee does
 * not.
 */
final class CommitteeSittingTest extends TestCase
{
    use BuildsProceedings;

    protected function setUp(): void
    {
        parent::setUp();

        // The concern arranges a department, a Commission, an approved proposal and
        // an open sitting — the eight steps every M7 test starts from.
        $this->bootProceeding();

        // Three candidates on the chart, so the list is a list.
        $this->officer(1, 'US1', $this->urCategoryId(), [2021 => 'क+', 2022 => 'क+', 2023 => 'क', 2024 => 'क', 2025 => 'क']);
        $this->officer(2, 'US2', $this->urCategoryId(), [2021 => 'क', 2022 => 'क', 2023 => 'क', 2024 => 'ख', 2025 => 'क']);
        $this->officer(3, 'US3', $this->urCategoryId(), [2021 => 'ख', 2022 => 'ख', 2023 => 'क', 2024 => 'ख', 2025 => 'ख']);
    }

    /**
     * Somebody seated on this DPC's committee holding **no role at all** — which is
     * what a committee member is.
     */
    private function seatedMember(): User
    {
        $member = User::factory()->create(['name' => 'Smt. Committee Member']);

        $this->assertTrue($member->roles->isEmpty());

        $this->seatAccountOnCommittee($this->proposal, $member);

        return $member;
    }

    private function firstCandidate(): DpcEvaluationRow
    {
        return $this->proposal->evaluationRows()->orderBy('sn')->firstOrFail();
    }

    /**
     * The moment the sitting was scheduled for, so a test can stand inside or
     * outside the window deliberately rather than by accident.
     */
    private function atTheSitting(): Carbon
    {
        $schedule = app(CommitteeSittingService::class)
            ->schedule($this->proposal);

        $this->assertNotNull($schedule);

        return $schedule->scheduledFor();
    }

    // ------------------------------------------------------------------- access

    public function test_a_committee_member_with_no_role_reaches_their_sittings(): void
    {
        $member = $this->seatedMember();

        $this->actingAs($member)->get(route('sitting.index'))
            ->assertOk()
            ->assertSee($this->proposal->dpc_code);
    }

    public function test_a_committee_member_sees_every_candidate(): void
    {
        $member = $this->seatedMember();

        $response = $this->actingAs($member)->get(route('sitting.show', $this->proposal))->assertOk();

        foreach ($this->proposal->evaluationRows as $row) {
            $response->assertSee($row->name);
        }
    }

    public function test_somebody_off_the_committee_is_refused(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('sitting.show', $this->proposal))->assertForbidden();
        $this->actingAs($stranger)->get(route('sitting.index'))->assertOk();
    }

    /**
     * A candidate reached through another DPC's URL is a 404 rather than a 403: the
     * row exists, but not on this proposal, and saying which would disclose it.
     */
    public function test_a_candidate_of_another_dpc_is_not_found(): void
    {
        $member = $this->seatedMember();

        // A real row on a real second proposal — a dangling foreign key would be
        // refused by the database rather than by the guard, which would prove
        // nothing about the guard.
        $other = $this->proposal->replicate(['uuid', 'dpc_code']);
        $other->dpc_code = 'DPC-2026-HE-9999';
        $other->save();

        $foreign = DpcEvaluationRow::query()->create([
            'dpc_proposal_id' => $other->id,
            'sn' => 1,
            'name' => 'Somebody Else',
            'dob' => '1970-01-01',
            'reservation_category_id' => $this->urCategoryId(),
        ]);

        $this->actingAs($member)
            ->get(route('sitting.candidate', [$this->proposal, $foreign]))
            ->assertNotFound();
    }

    // -------------------------------------------------------------- the record

    public function test_the_candidate_screen_shows_the_whole_record(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        $this->actingAs($member)
            ->get(route('sitting.candidate', [$this->proposal, $row]))
            ->assertOk()
            ->assertSee($row->name)
            ->assertSee(__('proceeding.sitting.record'));
    }

    /**
     * **The whole Evaluation Chart, not a slice of it** (business instruction,
     * 2026-09-07: *"not only the qualification — the complete Evaluation Chart
     * record is also required"*).
     *
     * The screen showed CR grades and nothing else of the chart, so a committee
     * could not see whether a loyalty certificate had been given or a property
     * return filed — two of the four things the chart records per year, and both of
     * them things a committee is there to notice.
     */
    public function test_the_candidate_screen_carries_the_whole_evaluation_chart(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        $response = $this->actingAs($member)
            ->get(route('sitting.candidate', [$this->proposal, $row]))
            ->assertOk();

        // Every column of the chart, by its heading.
        $response->assertSee(__('proceeding.sitting.chart'));
        $response->assertSee(__('proceeding.sitting.cr_grade'));
        $response->assertSee(__('proceeding.sitting.loyalty'));
        $response->assertSee(__('proceeding.sitting.property'));
        $response->assertSee(__('proceeding.sitting.tribal_area'));

        // And the officer-level fields the chart carries beside the years.
        $response->assertSee(__('proceeding.sitting.joined_service'));
        $response->assertSee(__('proceeding.sitting.posted_on_feeder'));
        $response->assertSee(__('proceeding.sitting.posted_on_promotion_post'));
        $response->assertSee(__('proceeding.sitting.tribal_years'));

        // Every CR year the chart holds is on the page.
        foreach ($row->crGrades as $grade) {
            $response->assertSee((string) $grade->year);
        }
    }

    /**
     * A year the department never filled reads "—"; one recorded as *not given*
     * reads "No".
     *
     * **A committee that cannot tell those apart cannot tell an incomplete record
     * from a defaulting officer**, which is half of what it is there to judge. The
     * distinction lives in the difference between `null` and `false`, and a view
     * that rendered both as "No" would lose it silently.
     */
    public function test_an_unrecorded_year_reads_differently_from_one_recorded_as_no(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        $year = $row->crGrades->first()->year;

        // Recorded, and recorded as not given.
        $row->loyaltyFlags()->where('year', $year)->update(['flag' => false]);
        // Not recorded at all.
        $row->propertyFlags()->where('year', $year)->delete();

        $chart = app(CommitteeSittingService::class)
            ->chartYears($row->fresh());

        $thisYear = collect($chart)->firstWhere('year', $year);

        $this->assertFalse($thisYear['loyalty'], 'recorded as not given');
        $this->assertNull($thisYear['property'], 'never recorded');
    }

    /**
     * A year with a loyalty certificate and no CR is a real state, and one the
     * screen must show rather than hide — it is the shape of an incomplete record.
     */
    public function test_a_year_with_no_cr_still_appears_on_the_chart(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        $orphanYear = 1999;
        $row->loyaltyFlags()->create(['year' => $orphanYear, 'flag' => true]);

        $chart = app(CommitteeSittingService::class)
            ->chartYears($row->fresh());

        $this->assertContains($orphanYear, array_column($chart, 'year'));

        $this->actingAs($member)
            ->get(route('sitting.candidate', [$this->proposal, $row]))
            ->assertOk()
            ->assertSee((string) $orphanYear);
    }

    // ---------------------------------------------------------- the call window

    /**
     * **Calling is refused before the sitting.** The rule as business stated it: the
     * call activates on the date and time the DPC is scheduled for.
     *
     * Enforced on the request and not only by disabling a button — a screen left
     * open from the day before would otherwise still post.
     */
    public function test_a_candidate_cannot_be_called_before_the_sitting(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        Carbon::setTestNow($this->atTheSitting()->copy()->subDay());

        $this->actingAs($member)
            ->post(route('sitting.call', [$this->proposal, $row]))
            ->assertSessionHasErrors('call');

        $this->assertSame(0, DpcCandidateCall::query()->count());
    }

    public function test_a_candidate_cannot_be_called_after_the_day_of_the_sitting(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        Carbon::setTestNow($this->atTheSitting()->copy()->addDay());

        $this->actingAs($member)
            ->post(route('sitting.call', [$this->proposal, $row]))
            ->assertSessionHasErrors('call');
    }

    public function test_a_candidate_is_called_at_the_sitting(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        Carbon::setTestNow($this->atTheSitting());

        $this->actingAs($member)
            ->post(route('sitting.call', [$this->proposal, $row]))
            ->assertSessionHasNoErrors();

        $call = DpcCandidateCall::query()->sole();

        $this->assertSame($row->id, $call->dpc_evaluation_row_id);
        $this->assertSame($member->id, $call->called_by);
        $this->assertNotNull($call->called_at);
        $this->assertNull($call->concluded_at);
    }

    /**
     * The sitting runs on into the day. A committee scheduled for 14:00 and still
     * working at 17:00 is an ordinary sitting, not a rule breach.
     */
    public function test_the_window_stays_open_for_the_rest_of_the_day(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        Carbon::setTestNow($this->atTheSitting()->copy()->addHours(3));

        $this->actingAs($member)
            ->post(route('sitting.call', [$this->proposal, $row]))
            ->assertSessionHasNoErrors();
    }

    /**
     * Calling the same candidate back re-stamps the one row. The question the screen
     * answers is where the committee has got to, not how many times somebody was
     * called.
     */
    public function test_calling_a_candidate_twice_keeps_one_record(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        Carbon::setTestNow($this->atTheSitting());

        $this->actingAs($member)->post(route('sitting.call', [$this->proposal, $row]));
        $this->actingAs($member)->post(route('sitting.call', [$this->proposal, $row]));

        $this->assertSame(1, DpcCandidateCall::query()->count());
    }

    // ------------------------------------------------------------- concluding

    public function test_the_committee_concludes_a_called_candidate(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        Carbon::setTestNow($this->atTheSitting());

        $this->actingAs($member)->post(route('sitting.call', [$this->proposal, $row]));

        $this->actingAs($member)
            ->post(route('sitting.conclude', [$this->proposal, $row]), ['remarks' => 'Seen by the committee.'])
            ->assertSessionHasNoErrors();

        $call = DpcCandidateCall::query()->sole();

        $this->assertTrue($call->isConcluded());
        $this->assertSame($member->id, $call->concluded_by);
        $this->assertSame('Seen by the committee.', $call->remarks);
    }

    /**
     * Concluding somebody the committee never called is a minute that does not
     * describe a sitting.
     */
    public function test_a_candidate_who_was_never_called_cannot_be_concluded(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        Carbon::setTestNow($this->atTheSitting());

        $this->actingAs($member)
            ->post(route('sitting.conclude', [$this->proposal, $row]))
            ->assertSessionHasErrors('call');

        $this->assertSame(0, DpcCandidateCall::query()->count());
    }

    /**
     * **No marks are recorded, and that is deliberate.** Business has not settled
     * the scheme — *"how the marking is to be done we will see later"* — so the
     * record carries the act, its author and its moment, and nothing that would
     * presume it.
     */
    public function test_concluding_records_no_score(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        Carbon::setTestNow($this->atTheSitting());

        $this->actingAs($member)->post(route('sitting.call', [$this->proposal, $row]));
        $this->actingAs($member)->post(route('sitting.conclude', [$this->proposal, $row]));

        $columns = array_keys(DpcCandidateCall::query()->sole()->getAttributes());

        foreach (['score', 'marks', 'grade', 'rating'] as $absent) {
            $this->assertNotContains($absent, $columns);
        }
    }
}
