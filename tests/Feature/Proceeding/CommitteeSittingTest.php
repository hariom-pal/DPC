<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Services\DpcRevisionService;
use App\Domain\Dpc\Services\DpcStageService;
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
use Illuminate\Support\Facades\DB;
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

    /**
     * 2026-11-24: a seat the department named shows nothing until the CGPSC Chairman
     * has allotted the DPC — not on the sitting list, the DPC, its records or Pending At Me.
     */
    public function test_a_member_sees_nothing_of_a_dpc_the_cgpsc_chairman_has_not_allotted(): void
    {
        $member = $this->seatedMember();
        // The allotment withdrawn (returned): the DPC is no longer the committee's.
        DB::table('dpc_allotments')->where('dpc_proposal_id', $this->proposal->id)->update(['returned_at' => now()]);

        $this->assertFalse(app(CommitteeSittingService::class)->allotted($this->proposal));
        $this->actingAs($member)->get(route('sitting.index'))->assertOk()->assertDontSee($this->proposal->dpc_code);
        $this->get(route('sitting.show', $this->proposal))->assertForbidden();
        $this->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertForbidden();
        $this->assertSame([], array_filter(app(DpcStageService::class)->pendingAt($member), fn (array $entry): bool => $entry['proposal']->id === $this->proposal->id));
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

    // --------------------------------- the meeting, and each member's Agree / Disagree

    /**
     * 2026-11-25: a member agrees once with the whole DPC — no OK, and no Agree, candidate
     * by candidate. The one Agree is recorded against every candidate.
     */
    public function test_a_member_agrees_once_with_the_whole_dpc(): void
    {
        Carbon::setTestNow($this->atTheSitting());
        $member = $this->seatedMember();
        $row = $this->firstCandidate();
        $candidates = $this->proposal->evaluationRows()->count();

        // No Agree on a candidate's page: it is on the DPC's list, once.
        $this->actingAs($member)->get(route('sitting.candidate', [$this->proposal, $row]))->assertOk()
            ->assertDontSee('id="record-agree"', false)->assertSee(__('proceeding.sitting.agree_on_list'))
            ->assertDontSee(__('proceeding.sitting.call'))->assertDontSee(__('proceeding.sitting.waiting_badge'));
        $this->get(route('sitting.show', $this->proposal))->assertOk()->assertSee('id="record-agree"', false)
            ->assertDontSee('data-disagree-form', false);

        $this->post(route('sitting.agree', $this->proposal))->assertSessionHas('status', __('proceeding.sitting.agree_recorded', ['count' => $candidates]));
        $this->assertSame($candidates, DpcCandidateReview::query()->where('user_id', $member->id)->count(), 'One Agree, every candidate.');
        $this->assertSame([], app(SittingMeetingService::class)->candidatesAwaitingOk($this->proposal, $member->id));

        // Again: nothing more.
        $this->post(route('sitting.agree', $this->proposal))->assertSessionHasErrors(['ok' => __('proceeding.sitting.already_agreed')]);
        $this->assertSame($candidates, DpcCandidateReview::query()->where('user_id', $member->id)->count());

        // Agreed: the Agree button is gone, and each candidate now offers only Disagree.
        $this->get(route('sitting.show', $this->proposal))->assertSee('id="agreed-badge"', false)->assertDontSee('id="record-agree"', false)
            ->assertSee('data-disagree-form="'.$row->id.'"', false)->assertSee('data-my-state="agreed"', false);
        $this->get(route('sitting.candidate', [$this->proposal, $row]))->assertSee('data-disagree-form="'.$row->id.'"', false);

        // Nobody off the committee records one.
        $this->actingAs(User::factory()->create())->post(route('sitting.agree', $this->proposal))->assertForbidden();
        $this->assertSame($candidates, DpcCandidateReview::query()->count());
    }

    /**
     * 2026-11-25: after the Agree, a member disagrees on any candidate with a remark of
     * any length; once, never withdrawn; read by the DPC Chairman alone. A Disagree does
     * not hold up Calculate — the DPC Chairman decides what to do with it.
     */
    public function test_a_member_disagrees_on_a_candidate_with_a_remark_for_the_dpc_chairman(): void
    {
        Carbon::setTestNow($this->atTheSitting());
        $member = $this->seatedMember();
        $other = User::factory()->create(['name' => 'Shri Other Member']);
        $this->seatAccountOnCommittee($this->proposal, $other);
        $row = $this->firstCandidate();
        $remark = 'The 2024 CR is not on the file. '.str_repeat('विस्तृत टिप्पणी। ', 800);

        // Not before the Agree.
        $this->actingAs($member)->post(route('sitting.candidate.disagree', [$this->proposal, $row]), ['remarks' => 'x'])
            ->assertSessionHasErrors(['ok' => __('proceeding.sitting.agree_first')]);

        $this->post(route('sitting.agree', $this->proposal));

        // The remark is required — and has no length limit.
        $this->post(route('sitting.candidate.disagree', [$this->proposal, $row]), ['remarks' => '  '])->assertSessionHasErrors('remarks');
        $this->post(route('sitting.candidate.disagree', [$this->proposal, $row]), ['remarks' => $remark])
            ->assertSessionHas('status', __('proceeding.sitting.disagree_recorded', ['name' => $row->name]));

        $review = DpcCandidateReview::query()->where('user_id', $member->id)->where('dpc_evaluation_row_id', $row->id)->sole();
        $this->assertNotNull($review->disagreed_at);
        $this->assertSame(trim($remark), $review->disagree_remarks, 'Kept whole, however long.');

        // Once, and never withdrawn or changed.
        $this->post(route('sitting.candidate.disagree', [$this->proposal, $row]), ['remarks' => 'Changed my mind.'])
            ->assertSessionHasErrors(['ok' => __('proceeding.sitting.already_disagreed')]);
        $this->assertSame(trim($remark), $review->fresh()->disagree_remarks);

        // The member sees their own; the remark stays off the timeline.
        $this->get(route('sitting.show', $this->proposal))->assertSee('data-my-state="disagreed"', false)->assertDontSee('data-disagree-form="'.$row->id.'"', false);
        $this->assertSame(1, ActivityTimeline::query()->where('event', 'dpc_sitting.candidate_disagreed')->count());
        $this->assertFalse(ActivityTimeline::query()->where('description', 'like', '%2024 CR is not on the file%')->exists());

        // Another member does not read it; the DPC Chairman does, on the list and the record.
        $this->actingAs($other)->get(route('sitting.show', $this->proposal))->assertDontSee('2024 CR is not on the file')->assertDontSee('data-disagreement=', false);
        $this->get(route('sitting.candidate', [$this->proposal, $row]))->assertDontSee('2024 CR is not on the file');
        $this->memberOne->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();
        $this->actingAs($this->memberOne)->get(route('sitting.show', $this->proposal))->assertSee('data-disagreement="'.$row->id.'"', false)->assertSee('2024 CR is not on the file');
        $this->get(route('sitting.candidate', [$this->proposal, $row]))->assertSee('id="candidate-disagreements"', false)->assertSee('2024 CR is not on the file');

        // A Disagree is not an Agree still awaited: it does not hold up Calculate.
        $this->assertNotContains($member->id, array_column(app(CalculationGate::class)->pendingMembers($this->proposal->fresh()), 'user_id'));
    }

    /**
     * 2026-11-25: the DPC Chairman sends an accepted DPC back to the Secretary, with a
     * remark, until its result is approved; then it goes as a rejected DPC goes.
     */
    public function test_the_dpc_chairman_sends_the_accepted_dpc_back_to_the_secretary(): void
    {
        Carbon::setTestNow($this->atTheSitting());
        $member = $this->seatedMember();
        $this->memberOne->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();

        // A member does not.
        $this->actingAs($member)->get(route('sitting.show', $this->proposal))->assertDontSee('id="chairman-return"', false);
        $this->post(route('sitting.return', $this->proposal), ['return_remarks' => 'Back.'])->assertForbidden();

        // Not once the result is approved.
        $status = $this->proceeding->status->value;
        DB::table('dpc_proceedings')->where('id', $this->proceeding->id)->update(['status' => 'result_approved']);
        $this->actingAs($this->memberOne)->get(route('sitting.show', $this->proposal))->assertDontSee('id="chairman-return"', false);
        $this->post(route('sitting.return', $this->proposal), ['return_remarks' => 'Back.'])->assertForbidden();
        // A concluded proceeding is never edited through the model; the test restores it directly.
        DB::table('dpc_proceedings')->where('id', $this->proceeding->id)->update(['status' => $status]);

        // The DPC Chairman, with the remark. (A real DPC opens revision 1 on creation; this fixture does not.)
        app(DpcRevisionService::class)->open($this->proposal, $this->memberOne);
        $this->get(route('sitting.show', $this->proposal))->assertSee('id="chairman-return"', false);
        $this->post(route('sitting.return', $this->proposal), ['return_remarks' => ''])->assertSessionHasErrors('return_remarks');
        $this->post(route('sitting.return', $this->proposal), ['return_remarks' => 'A member disagrees on US1: the 2024 CR is missing.'])
            ->assertSessionHas('status', __('dpc.committee_decision.returned_to_secretary'));

        $proposal = $this->proposal->fresh();
        $this->assertSame('pending_secretary', $proposal->status->value);
        $this->assertSame('secretary', $proposal->committeeReturnStage());
        $this->assertSame(1, DpcCommitteeDecision::query()->where('decision', DpcCommitteeDecision::RETURNED)->where('user_id', $this->memberOne->id)->count());
        $this->assertFalse(app(CalculationGate::class)->chairmanAccepted($proposal), 'Back at the committee, it is accepted afresh.');
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
     * 2026-11-18: Agree is recorded at the sitting itself — the day and hour scheduled —
     * and only once the DPC Chairman accepted the DPC. The CGPSC Chairman's own seat
     * is not among the members awaited.
     */
    public function test_agree_waits_for_the_chairmans_accept_and_the_sitting(): void
    {
        $member = $this->seatedMember();
        $row = $this->firstCandidate();
        $meetings = app(SittingMeetingService::class);

        $this->assertNotContains(
            DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->where('capacity', 'cgpsc_chairman')->value('user_id'),
            $meetings->requiredMemberIds($this->proposal),
        );

        // The day before the sitting: no Agree.
        Carbon::setTestNow($this->atTheSitting()->copy()->subDay());
        $this->actingAs($member)->post(route('sitting.agree', $this->proposal))->assertSessionHasErrors('ok');

        // At the sitting, but not accepted by the DPC Chairman: no OK either.
        Carbon::setTestNow($this->atTheSitting());
        DpcCommitteeDecision::query()->delete();
        $this->post(route('sitting.agree', $this->proposal))->assertSessionHasErrors(['ok' => __('proceeding.sitting.ok_after_accept')]);
        $this->post(route('sitting.candidate.disagree', [$this->proposal, $row]), ['remarks' => 'x'])->assertSessionHasErrors(['ok' => __('proceeding.sitting.ok_after_accept')]);
        $this->assertSame(0, DpcCandidateReview::query()->count());

        // Accepted: the Agree is taken.
        DpcCommitteeDecision::query()->create(['dpc_proposal_id' => $this->proposal->id, 'user_id' => $this->memberOne->id, 'member_name' => 'Member One', 'decision' => 'agreed', 'decided_at' => now()]);
        $this->post(route('sitting.agree', $this->proposal))->assertSessionHasNoErrors();
        $this->assertSame($this->proposal->evaluationRows()->count(), DpcCandidateReview::query()->count());
    }

    /** A correction at the sitting changes the record: its Agrees count no more, and a fresh Agree is needed. */
    public function test_a_correction_asks_for_a_fresh_agree_on_that_candidate_only(): void
    {
        Carbon::setTestNow($this->atTheSitting());
        $member = $this->seatedMember();
        $meetings = app(SittingMeetingService::class);
        $meetings->agree($this->proposal, $member);
        $this->assertSame([], $meetings->candidatesAwaitingOk($this->proposal, $member->id));

        $corrected = $this->firstCandidate();
        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::TribalAreaServiceYears, $corrected->sn, null, '2',
            'Tribal service confirmed at the sitting.',
        );

        $this->assertSame([$corrected->id], $meetings->candidatesAwaitingOk($this->proposal, $member->id), 'Only the corrected candidate.');
        $this->assertSame(1, DpcCandidateReview::query()->whereNotNull('invalidated_at')->where('invalidation_reason', 'correction')->count(), 'Kept, not deleted.');

        // Calculate waits for the fresh Agree, which covers that candidate alone.
        $this->assertContains('ok_pending', array_column(app(CalculationGate::class)->pendingMembers($this->proposal->fresh()), 'state'));
        $this->assertSame(1, $meetings->agree($this->proposal, $member));
        $this->assertSame([], $meetings->candidatesAwaitingOk($this->proposal, $member->id));
    }

    /**
     * 2026-11-18: on the day the DPC is held, the DPC Chairman edits any candidate's
     * record themselves — nothing is sent back. Each edit is on the timeline and asks
     * every member for a fresh Agree on that candidate. Nobody else, and not before.
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

        // At the sitting: a member agrees, then the DPC Chairman edits.
        Carbon::setTestNow($this->atTheSitting());
        $meetings = app(SittingMeetingService::class);
        $meetings->agree($this->proposal, $member);

        $this->actingAs($this->memberOne)->get(route('sitting.candidate', [$this->proposal, $row]))->assertSee('id="chairman-edit-link"', false);
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $row]))->assertOk()->assertSee('id="chairman-sitting-edit"', false);
        $this->put(route('dpc-proposals.employees.basic', [$this->proposal, $row]), $basic)->assertSessionHasNoErrors();

        $this->assertSame('SR/12/1995', $row->fresh()->dob_order_number);
        $this->assertSame(1, ActivityTimeline::query()->where('event', ChairmanSittingEdit::EVENT)->count());
        $this->assertSame([$row->id], array_values(array_intersect([$row->id], $meetings->candidatesAwaitingOk($this->proposal, $member->id))), 'The Agree on the edited record is to be given again.');
        $this->assertNotNull(app(ChairmanSittingEdit::class)->lastEditAt($this->proposal));

        // Any other member: no edit.
        $this->actingAs($member)->get(route('sitting.candidate', [$this->proposal, $row]))->assertDontSee('id="chairman-edit-link"', false);
        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $row]))->assertForbidden();
        $this->put(route('dpc-proposals.employees.basic', [$this->proposal, $row]), $basic)->assertForbidden();

        // Not once the DPC Chairman's Accept is gone (a rejection reopens the department's correction instead).
        DpcCommitteeDecision::query()->delete();
        $this->actingAs($this->memberOne)->put(route('dpc-proposals.employees.basic', [$this->proposal, $row]), $basic)->assertForbidden();
    }

    /**
     * 2026-11-27: the DPC Chairman — the person the DPC is allotted to, who need hold no
     * seat — agrees like any member: the Agree card is theirs, and Calculate waits on it.
     */
    public function test_a_dpc_chairman_without_a_seat_agrees_like_any_member(): void
    {
        Carbon::setTestNow($this->atTheSitting());
        DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->where('user_id', $this->memberOne->id)->update(['user_id' => null]);
        $this->memberOne->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();
        $meetings = app(SittingMeetingService::class);

        $this->assertSame($this->memberOne->id, $meetings->unseatedDpcChairman($this->proposal)?->id);
        $this->assertContains($this->memberOne->id, $meetings->requiredMemberIds($this->proposal));
        $this->assertContains($this->memberOne->id, array_column(app(CalculationGate::class)->pendingMembers($this->proposal->fresh()), 'user_id'), 'Calculate waits on the DPC Chairman too.');

        $this->actingAs($this->memberOne)->get(route('sitting.show', $this->proposal))->assertOk()->assertSee('id="record-agree"', false);
        $this->post(route('sitting.agree', $this->proposal))->assertSessionHasNoErrors();

        $this->assertNotContains($this->memberOne->id, array_column(app(CalculationGate::class)->pendingMembers($this->proposal->fresh()), 'user_id'));
        $this->get(route('sitting.show', $this->proposal))->assertSee('id="agreed-badge"', false);
    }

    /** 2026-11-27: once the sitting's day has gone by, the page says so — not that it will open. */
    public function test_after_the_sitting_day_the_reason_says_it_has_passed(): void
    {
        $member = $this->seatedMember();
        $when = $this->atTheSitting()->format('d-m-Y H:i');
        Carbon::setTestNow($this->atTheSitting()->copy()->addDays(2));

        $this->actingAs($member)->get(route('sitting.show', $this->proposal))->assertOk()
            ->assertDontSee('id="record-agree"', false)
            ->assertSee(__('proceeding.sitting.day_passed', ['when' => $when]));
        $this->post(route('sitting.agree', $this->proposal))->assertSessionHasErrors(['ok' => __('proceeding.sitting.day_passed', ['when' => $when])]);
        $this->assertSame(__('dpc.calculation_gate.sitting_passed', ['when' => $when]), app(CalculationGate::class)->refusal($this->proposal->fresh(), $this->memberOne));
    }

    /** Two presses at once, or twice: one Agree on each candidate. */
    public function test_a_repeated_agree_makes_one_record_a_candidate(): void
    {
        Carbon::setTestNow($this->atTheSitting());
        $member = $this->seatedMember();

        $this->actingAs($member)->post(route('sitting.agree', $this->proposal));
        $this->actingAs($member)->post(route('sitting.agree', $this->proposal));

        try {
            app(SittingMeetingService::class)->agree($this->proposal, $member);
            $this->fail('A second Agree is refused.');
        } catch (WorkflowException) {
        }

        $this->assertSame($this->proposal->evaluationRows()->count(), DpcCandidateReview::query()->where('user_id', $member->id)->count());
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
