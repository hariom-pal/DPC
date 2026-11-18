<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Models\DpcCandidateReview;
use App\Domain\Proceeding\Models\DpcSittingMeeting;
use App\Domain\Proceeding\Services\CalculationGate;
use App\Domain\Proceeding\Services\ChairmanSittingEdit;
use App\Domain\Proceeding\Services\CommitteeSittingService;
use App\Domain\Proceeding\Services\SittingMeetingService;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
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

    // ------------------------------------------- the meeting and each member's OK

    /**
     * 2026-11-18: no candidate is called before a DPC. Each member opens each record
     * and records an OK on it — once — and the list shows whose OK is in.
     */
    public function test_each_member_records_an_ok_on_each_candidate(): void
    {
        Carbon::setTestNow($this->atTheSitting());
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        $this->actingAs($member)->get(route('sitting.candidate', [$this->proposal, $row]))->assertOk()
            ->assertSee('id="record-ok"', false)
            ->assertDontSee(__('proceeding.sitting.call'))->assertDontSee(__('proceeding.sitting.waiting_badge'));

        $this->post(route('sitting.candidate.ok', [$this->proposal, $row]))->assertSessionHas('status');
        $this->post(route('sitting.candidate.ok', [$this->proposal, $row]));
        $this->assertSame(1, DpcCandidateReview::query()->where('user_id', $member->id)->count(), 'Once per member per candidate.');

        $this->get(route('sitting.candidate', [$this->proposal, $row]))->assertDontSee('id="record-ok"', false)->assertSee(__('proceeding.sitting.ok_yours'));
        $this->get(route('sitting.show', $this->proposal))->assertSee('data-candidate-ok="'.$row->id.'"', false)->assertSee(__('proceeding.sitting.ok_yours'));

        // Nobody off the committee records one.
        $this->actingAs(User::factory()->create())->post(route('sitting.candidate.ok', [$this->proposal, $row]))->assertForbidden();
        $this->assertSame(1, DpcCandidateReview::query()->count());
    }

    /**
     * Only the DPC Chairman (the allottee) starts the meeting; the portal makes the
     * room, and every member joins it through the portal.
     */
    public function test_the_dpc_chairman_starts_the_meeting_and_members_join_it(): void
    {
        Carbon::setTestNow($this->atTheSitting());
        config(['dpc.meeting.base_url' => 'https://vc.example.test']);
        $member = $this->seatedMember();

        // A member who is not the DPC Chairman cannot start it.
        $this->actingAs($member)->get(route('sitting.show', $this->proposal))->assertOk()
            ->assertDontSee('id="initiate-meeting"', false)->assertSee(__('proceeding.sitting.meeting_none'));
        $this->post(route('sitting.meeting.initiate', $this->proposal))->assertSessionHasErrors('meeting');
        $this->get(route('sitting.meeting', $this->proposal))->assertRedirect(route('sitting.show', $this->proposal));
        $this->assertSame(0, DpcSittingMeeting::query()->count());

        // The DPC Chairman starts it.
        $this->memberOne->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();
        $this->actingAs($this->memberOne)->get(route('sitting.show', $this->proposal))->assertSee('id="initiate-meeting"', false);
        $this->post(route('sitting.meeting.initiate', $this->proposal))->assertSessionHas('status', __('proceeding.sitting.meeting_started'));

        $meeting = DpcSittingMeeting::query()->sole();
        $this->assertStringStartsWith('https://vc.example.test/eDPC-', $meeting->url);
        $this->assertSame($this->memberOne->id, $meeting->initiated_by);

        // Every member joins through the portal; the room itself is never on the page.
        $this->actingAs($member)->get(route('sitting.show', $this->proposal))
            ->assertSee('id="join-meeting"', false)->assertDontSee($meeting->url, false);
        $this->get(route('sitting.meeting', $this->proposal))->assertRedirect($meeting->url);
        $this->actingAs(User::factory()->create())->get(route('sitting.meeting', $this->proposal))->assertForbidden();
    }

    /**
     * 2026-11-18: OK is recorded at the sitting itself — the day and hour scheduled —
     * and only once the DPC Chairman accepted the DPC. The CGPSC Chairman's own seat
     * is not among the members awaited.
     */
    public function test_ok_waits_for_the_chairmans_accept_and_the_sitting(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();
        $meetings = app(SittingMeetingService::class);

        $this->assertNotContains(
            DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->where('capacity', 'cgpsc_chairman')->value('user_id'),
            $meetings->requiredMemberIds($this->proposal),
        );

        // The day before the sitting: no OK.
        Carbon::setTestNow($this->atTheSitting()->copy()->subDay());
        $this->actingAs($member)->post(route('sitting.candidate.ok', [$this->proposal, $row]))->assertSessionHasErrors('ok');

        // At the sitting, but not accepted by the DPC Chairman: no OK either.
        Carbon::setTestNow($this->atTheSitting());
        DpcCommitteeDecision::query()->delete();
        $this->post(route('sitting.candidate.ok', [$this->proposal, $row]))->assertSessionHasErrors(['ok' => __('proceeding.sitting.ok_after_accept')]);
        $this->assertSame(0, DpcCandidateReview::query()->count());

        // Accepted: the OK is taken.
        DpcCommitteeDecision::query()->create(['dpc_proposal_id' => $this->proposal->id, 'user_id' => $this->memberOne->id, 'member_name' => 'Member One', 'decision' => 'agreed', 'decided_at' => now()]);
        $this->post(route('sitting.candidate.ok', [$this->proposal, $row]))->assertSessionHasNoErrors();
        $this->assertSame(1, DpcCandidateReview::query()->count());
    }

    /** A correction at the sitting changes the record: its OKs count no more, and a fresh OK is needed. */
    public function test_a_correction_asks_for_a_fresh_ok_on_that_candidate_only(): void
    {
        Carbon::setTestNow($this->atTheSitting());
        $member = $this->seatedMember();
        $meetings = app(SittingMeetingService::class);
        foreach ($this->proposal->evaluationRows as $row) {
            $meetings->review($this->proposal, $row, $member);
        }
        $this->assertSame([], $meetings->candidatesAwaitingOk($this->proposal, $member->id));

        $corrected = $this->firstCandidate();
        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::TribalAreaServiceYears, $corrected->sn, null, '2',
            'Tribal service confirmed at the sitting.',
        );

        $this->assertSame([$corrected->id], $meetings->candidatesAwaitingOk($this->proposal, $member->id), 'Only the corrected candidate.');
        $this->assertSame(1, DpcCandidateReview::query()->whereNotNull('invalidated_at')->where('invalidation_reason', 'correction')->count(), 'Kept, not deleted.');

        // Calculate waits for the fresh OK.
        $this->assertContains('ok_pending', array_column(app(CalculationGate::class)->pendingMembers($this->proposal->fresh()), 'state'));
        $meetings->review($this->proposal, $corrected, $member);
        $this->assertSame([], $meetings->candidatesAwaitingOk($this->proposal, $member->id));
    }

    /**
     * 2026-11-18: on the day the DPC is held, the DPC Chairman edits any candidate's
     * record themselves — nothing is sent back. Each edit is on the timeline and asks
     * every member for a fresh OK on that candidate. Nobody else, and not before.
     */
    public function test_the_dpc_chairman_edits_a_record_themselves_at_the_sitting(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();
        $this->memberOne->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();
        $basic = [
            'dob_order_number' => 'SR/12/1995', 'dob_order_date' => '1995-07-01',
            'joining_order_number' => 'APPT/45/1995', 'joining_order_date' => '1995-07-01',
            'service_record_undertaking' => '1',
        ];

        // The day before: not even the DPC Chairman.
        Carbon::setTestNow($this->atTheSitting()->copy()->subDay());
        $this->actingAs($this->memberOne)->get(route('sitting.candidate', [$this->proposal, $row]))->assertOk()->assertDontSee('id="chairman-edit-link"', false);
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $row]))->assertForbidden();
        $this->put(route('dpc-proposals.employees.basic', [$this->proposal, $row]), $basic)->assertForbidden();

        // At the sitting: a member OKs, then the DPC Chairman edits.
        Carbon::setTestNow($this->atTheSitting());
        $meetings = app(SittingMeetingService::class);
        $meetings->review($this->proposal, $row, $member);

        $this->actingAs($this->memberOne)->get(route('sitting.candidate', [$this->proposal, $row]))->assertSee('id="chairman-edit-link"', false);
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $row]))->assertOk()->assertSee('id="chairman-sitting-edit"', false);
        $this->put(route('dpc-proposals.employees.basic', [$this->proposal, $row]), $basic)->assertSessionHasNoErrors();

        $this->assertSame('SR/12/1995', $row->fresh()->dob_order_number);
        $this->assertSame(1, ActivityTimeline::query()->where('event', ChairmanSittingEdit::EVENT)->count());
        $this->assertSame([$row->id], array_values(array_intersect([$row->id], $meetings->candidatesAwaitingOk($this->proposal, $member->id))), 'The OK on the edited record is to be given again.');
        $this->assertNotNull(app(ChairmanSittingEdit::class)->lastEditAt($this->proposal));

        // Any other member: no edit.
        $this->actingAs($member)->get(route('sitting.candidate', [$this->proposal, $row]))->assertDontSee('id="chairman-edit-link"', false);
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $row]))->assertForbidden();
        $this->put(route('dpc-proposals.employees.basic', [$this->proposal, $row]), $basic)->assertForbidden();

        // Not once the DPC Chairman's Accept is gone (a rejection reopens the department's correction instead).
        DpcCommitteeDecision::query()->delete();
        $this->actingAs($this->memberOne)->put(route('dpc-proposals.employees.basic', [$this->proposal, $row]), $basic)->assertForbidden();
    }

    /** Two presses at once, or twice: one OK. */
    public function test_a_repeated_ok_makes_one_record(): void
    {
        Carbon::setTestNow($this->atTheSitting());
        $member = $this->seatedMember();
        $row = $this->firstCandidate();

        $this->actingAs($member)->post(route('sitting.candidate.ok', [$this->proposal, $row]));
        $this->actingAs($member)->post(route('sitting.candidate.ok', [$this->proposal, $row]));
        app(SittingMeetingService::class)->review($this->proposal, $row, $member);

        $this->assertSame(1, DpcCandidateReview::query()->where('user_id', $member->id)->count());
    }

    /** Outside development a meeting needs the Commission's own server; the public one is never used there. */
    public function test_no_meeting_starts_without_a_configured_server_outside_development(): void
    {
        config(['dpc.meeting.base_url' => null]);
        $this->app['env'] = 'production';
        $meetings = app(SittingMeetingService::class);

        $this->assertNull($meetings->serverUrl());
        try {
            $meetings->initiate($this->proposal, $this->memberOne);
            $this->fail('No meeting without a server.');
        } catch (WorkflowException $e) {
            $this->assertSame(__('proceeding.sitting.meeting_server_missing'), $e->getMessage());
        }
        $this->assertSame(0, DpcSittingMeeting::query()->count());

        config(['dpc.meeting.base_url' => 'https://vc.cgpsc.example/']);
        $this->assertSame('https://vc.cgpsc.example', $meetings->serverUrl());
        $this->app['env'] = 'testing';
    }
}
