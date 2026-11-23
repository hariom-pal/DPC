<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\PromotionRuleCheck;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcCandidateReview;
use App\Domain\Proceeding\Services\CalculationGate;
use App\Domain\Proceeding\Services\ChairmanSittingEdit;
use App\Domain\Proceeding\Services\CommitteeSittingService;
use App\Domain\Proceeding\Services\SealedCoverService;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * The DPC Chairman's own edits at the sitting (business instruction, 2026-11-18),
 * and the authorization around the Employee Record routes, which carry no role
 * middleware: the proposal policy alone decides who reads and who writes.
 */
class ChairmanSittingEditTest extends TestCase
{
    use BuildsProceedings;

    private const BASIC = [
        'dob_order_number' => 'SR/12/1995', 'dob_order_date' => '1995-07-01',
        'joining_order_number' => 'APPT/45/1995', 'joining_order_date' => '1995-07-01',
        'service_record_undertaking' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProceeding();
        $this->officer(1, 'US1', $this->urCategoryId(), [2021 => 'क+', 2022 => 'क+', 2023 => 'क', 2024 => 'क', 2025 => 'क']);
        $this->officer(2, 'US2', $this->urCategoryId(), [2021 => 'क', 2022 => 'क', 2023 => 'क', 2024 => 'ख', 2025 => 'क']);
        $this->memberOne->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();
        Storage::fake('local');
    }

    private function atTheSitting(): void
    {
        Carbon::setTestNow(app(CommitteeSittingService::class)->schedule($this->proposal)?->scheduledFor());
    }

    private function row(int $sn = 1, ?DpcProposal $proposal = null): DpcEvaluationRow
    {
        return ($proposal ?? $this->proposal)->evaluationRows()->where('sn', $sn)->firstOrFail();
    }

    /**
     * Every Employee Record route, as [method, url, payload].
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function recordRoutes(DpcEvaluationRow $row, ?DpcProposal $proposal = null): array
    {
        $proposal ??= $this->proposal;
        $args = [$proposal, $row];

        return [
            ['get', route('dpc-proposals.employees.show', $args), []],
            ['put', route('dpc-proposals.employees.basic', $args), self::BASIC],
            ['post', route('dpc-proposals.employees.cr.save', $args), ['year' => 2025, 'put_up' => '1', 'grade' => 'क']],
            ['delete', route('dpc-proposals.employees.cr.remove', [...$args, 2025]), []],
            ['post', route('dpc-proposals.employees.property.save', $args), ['year' => 2025, 'submitted_on' => '2025-01-10']],
            ['delete', route('dpc-proposals.employees.property.remove', [...$args, 2025]), []],
            ['put', route('dpc-proposals.employees.ph', $args), ['is_ph' => '0']],
            ['put', route('dpc-proposals.employees.vigilance', $args), ['enquiry_ongoing' => '0', 'punishment_effective' => '0']],
            ['put', route('dpc-proposals.employees.declarations', $args), []],
            ['post', route('dpc-proposals.employees.documents.store', $args), ['kind' => 'dob_proof', 'document' => UploadedFile::fake()->create('dob.pdf', 10, 'application/pdf')]],
            ['get', route('dpc-proposals.record-gaps.index', $args), []],
            ['post', route('dpc-proposals.record-gaps.store', $args), ['kind' => DpcEvaluationRecordGap::KIND_CR, 'year' => 2013, 'record_reason_id' => RecordReason::query()->value('id'), 'document' => UploadedFile::fake()->create('gap.pdf', 10, 'application/pdf')]],
        ];
    }

    private function assertEveryRouteRefused(User $user, DpcEvaluationRow $row, ?DpcProposal $proposal = null, int $status = 403): void
    {
        foreach ($this->recordRoutes($row, $proposal) as [$method, $url, $payload]) {
            $this->actingAs($user)->{$method}($url, $payload)->assertStatus($status);
        }
    }

    public function test_the_allotted_dpc_chairman_edits_at_the_sitting_and_only_that_candidate_is_okd_afresh(): void
    {
        $this->atTheSitting();
        $this->everyMemberOksEveryCandidate($this->proposal);
        $gate = app(CalculationGate::class);
        $this->assertNull($gate->refusal($this->proposal->fresh(), $this->memberOne));

        $this->actingAs($this->memberOne)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row()]))->assertOk();
        $this->put(route('dpc-proposals.employees.basic', [$this->proposal, $this->row()]), self::BASIC)->assertSessionHasNoErrors();
        $this->assertSame('SR/12/1995', $this->row()->dob_order_number);

        // Audited.
        $event = ActivityTimeline::query()->where('event', ChairmanSittingEdit::EVENT)->sole();
        $this->assertSame($this->memberOne->id, (int) $event->causer_id);
        $this->assertSame('basic', $event->properties['section'] ?? null);

        // Only the edited candidate's OKs stop counting.
        $this->assertSame(0, DpcCandidateReview::query()->where('dpc_evaluation_row_id', $this->row()->id)->whereNull('invalidated_at')->count());
        $this->assertGreaterThan(0, DpcCandidateReview::query()->where('dpc_evaluation_row_id', $this->row(2)->id)->whereNull('invalidated_at')->count());

        // Calculate waits for the fresh OK, then opens again.
        $this->assertSame(__('dpc.calculation_gate.awaiting_ok'), $gate->refusal($this->proposal->fresh(), $this->memberOne));
        $this->everyMemberOksEveryCandidate($this->proposal);
        $this->assertNull($gate->refusal($this->proposal->fresh(), $this->memberOne));
    }

    /**
     * 2026-11-22: the DPC Chairman may change any candidate's grading at the sitting, is
     * asked why, and the total is worked out on the new grading. The change, with its
     * reason, is on the timeline, the employee record and the sitting's candidate page.
     */
    public function test_the_dpc_chairman_changes_a_grading_with_its_reason_and_the_total_follows(): void
    {
        $this->atTheSitting();
        $row = $this->row(2);
        $url = route('dpc-proposals.employees.cr.save', [$this->proposal, $row]);
        $payload = ['year' => 2024, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => 'क+'];
        $before = app(PromotionRuleCheck::class)->check($row, $this->proposal)['total'];

        // No reason: refused, and the grading stays as it was.
        $this->actingAs($this->memberOne)->post($url, $payload)->assertSessionHasErrors('chairman_reason');
        $this->assertSame('ख', $this->row(2)->crGrades()->where('year', 2024)->value('grade'));
        $this->assertSame(0, ActivityTimeline::query()->where('event', ChairmanSittingEdit::GRADE_EVENT)->count());

        $this->post($url, $payload + ['chairman_reason' => 'The reviewing authority upgraded it on representation.'])->assertSessionHasNoErrors();
        $this->assertSame('क+', $this->row(2)->crGrades()->where('year', 2024)->value('grade'));

        $event = ActivityTimeline::query()->where('event', ChairmanSittingEdit::GRADE_EVENT)->sole();
        $this->assertSame($this->memberOne->id, (int) $event->causer_id);
        $this->assertSame(['ख', 'क+', 'The reviewing authority upgraded it on representation.'], [$event->properties['from'], $event->properties['to'], $event->properties['reason']]);

        // The new total, on the new grading.
        $after = app(PromotionRuleCheck::class)->check($this->row(2), $this->proposal)['total'];
        if ($before !== null) {
            $this->assertNotSame($before, $after);
        }

        $this->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row(2), 'cr_year' => 2024]))->assertOk()
            ->assertSee('id="chairman-reason-field"', false)
            ->assertSee('data-grade-change-year="2024"', false)
            ->assertSee('The reviewing authority upgraded it on representation.');
        $this->get(route('sitting.candidate', [$this->proposal, $this->row(2)]))->assertOk()
            ->assertSee('data-grade-change-year="2024"', false);

        // Saved again unchanged: no reason asked, nothing new recorded.
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, ActivityTimeline::query()->where('event', ChairmanSittingEdit::GRADE_EVENT)->count());
    }

    public function test_the_department_is_never_asked_for_the_chairmans_reason(): void
    {
        $this->assertFalse($this->authorizedOfficer->can('editAtSitting', $this->proposal));
        $this->actingAs($this->authorizedOfficer)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row(2), 'cr_year' => 2024]))
            ->assertDontSee('id="chairman-reason-field"', false);
    }

    public function test_every_record_route_admits_the_allotted_dpc_chairman_at_the_sitting(): void
    {
        $this->atTheSitting();

        foreach ($this->recordRoutes($this->row(2)) as [$method, $url, $payload]) {
            $status = $this->actingAs($this->memberOne)->{$method}($url, $payload)->getStatusCode();
            $this->assertContains($status, [200, 302], strtoupper($method).' '.$url.' answered '.$status);
        }

        $this->assertGreaterThan(0, ActivityTimeline::query()->where('event', ChairmanSittingEdit::EVENT)->count());
    }

    public function test_an_edit_after_the_result_reopens_calculate_only_after_fresh_oks(): void
    {
        $this->atTheSitting();
        $this->everyMemberOksEveryCandidate($this->proposal);
        $this->calculate();
        $gate = app(CalculationGate::class);
        $this->assertSame(__('dpc.calculation_gate.already_calculated'), $gate->refusal($this->proposal->fresh(), $this->memberOne));

        $this->travel(1)->minutes();
        $this->actingAs($this->memberOne)->put(route('dpc-proposals.employees.basic', [$this->proposal, $this->row()]), self::BASIC)->assertSessionHasNoErrors();

        $this->assertSame(__('dpc.calculation_gate.awaiting_ok'), $gate->refusal($this->proposal->fresh(), $this->memberOne));
        $this->everyMemberOksEveryCandidate($this->proposal);
        $this->assertNull($gate->refusal($this->proposal->fresh(), $this->memberOne), 'The edit is a change the result has not seen.');
    }

    public function test_not_before_the_sitting(): void
    {
        $this->atTheSitting();
        Carbon::setTestNow(now()->subDay());

        $this->assertEveryRouteRefused($this->memberOne, $this->row());
    }

    public function test_not_before_the_dpc_chairman_accepts(): void
    {
        $this->atTheSitting();
        DpcCommitteeDecision::query()->delete();

        $this->assertEveryRouteRefused($this->memberOne, $this->row());
    }

    public function test_not_while_a_return_is_pending(): void
    {
        $this->atTheSitting();
        $this->proposal->forceFill(['committee_returned_at' => now()])->save();

        $this->assertEveryRouteRefused($this->memberOne, $this->row());
    }

    public function test_not_once_the_proceeding_is_approved_or_cancelled_or_before_one_is_open(): void
    {
        $this->atTheSitting();

        $this->proceeding->forceFill(['status' => DpcProceedingStatus::ResultApproved->value])->save();
        $this->assertEveryRouteRefused($this->memberOne, $this->row());

        // A concluded proceeding is a permanent record: set below the model, as a fixture.
        DB::table('dpc_proceedings')->where('id', $this->proceeding->id)->update(['status' => DpcProceedingStatus::Cancelled->value]);
        $this->assertEveryRouteRefused($this->memberOne, $this->row());

        DB::table('dpc_proceedings')->where('id', $this->proceeding->id)->delete();
        $this->assertEveryRouteRefused($this->memberOne, $this->row());
    }

    public function test_a_role_less_committee_member_never_edits(): void
    {
        $this->atTheSitting();
        $member = User::factory()->create();
        $this->seatAccountOnCommittee($this->proposal, $member);
        $this->assertTrue($member->roles->isEmpty());

        $this->assertEveryRouteRefused($member, $this->row());
        $this->assertEveryRouteRefused($this->memberTwo, $this->row());
    }

    public function test_the_cgpsc_chairman_role_alone_gives_no_edit(): void
    {
        $this->atTheSitting();
        $this->assertTrue($this->chairman->hasRole(Role::Chairman->value));

        // The DPC is allotted to somebody else: the CGPSC Chairman's role is not enough.
        $this->assertFalse(app(ChairmanSittingEdit::class)->mayEdit($this->proposal->fresh(), $this->chairman));
        $this->assertEveryRouteRefused($this->chairman, $this->row());
    }

    public function test_the_cgpsc_chairman_who_allotted_the_dpc_to_themselves_edits_as_its_dpc_chairman(): void
    {
        // Allotted to themselves (2026-11-18); then their own Accept.
        $this->chairman->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();
        $this->seatAccountOnCommittee($this->proposal, $this->chairman);
        // As a fixture: the sitting is already scheduled, so the earlier allotment is
        // superseded directly — the state a self-allotment leaves behind.
        $earlier = DpcAllotment::query()->where('dpc_proposal_id', $this->proposal->id)->current()->firstOrFail();
        $mine = $earlier->replicate(['uuid']);
        $mine->allotted_to = $this->chairman->id;
        $mine->save();
        $earlier->forceFill(['superseded_by_id' => $mine->id])->save();
        $this->assertTrue(app(SealedCoverService::class)->isDpcChairman($this->proposal->fresh(), $this->chairman));

        $this->atTheSitting();
        // Not before their own Accept: the decisions recorded before are set aside (the fixture agreed for every seat).
        DpcCommitteeDecision::query()->where('dpc_proposal_id', $this->proposal->id)->delete();
        $this->assertEveryRouteRefused($this->chairman, $this->row());

        DpcCommitteeDecision::query()->create(['dpc_proposal_id' => $this->proposal->id, 'user_id' => $this->chairman->id, 'member_name' => $this->chairman->name, 'decision' => DpcCommitteeDecision::AGREED, 'decided_at' => now()]);
        $this->everyMemberOksEveryCandidate($this->proposal);

        foreach ($this->recordRoutes($this->row(2)) as [$method, $url, $payload]) {
            $status = $this->actingAs($this->chairman)->{$method}($url, $payload)->getStatusCode();
            $this->assertContains($status, [200, 302], strtoupper($method).' '.$url.' answered '.$status);
        }
        $this->put(route('dpc-proposals.employees.basic', [$this->proposal, $this->row()]), self::BASIC)->assertSessionHasNoErrors();

        // Audited as theirs; only the edited candidates' OKs voided.
        $this->assertGreaterThan(0, ActivityTimeline::query()->where('event', ChairmanSittingEdit::EVENT)->where('causer_id', $this->chairman->id)->count());
        $this->assertSame(0, DpcCandidateReview::query()->where('dpc_evaluation_row_id', $this->row()->id)->whereNull('invalidated_at')->count());

        // The former allottee no longer edits.
        $this->assertEveryRouteRefused($this->memberOne, $this->row());

        // Another DPC's candidate: no.
        $other = $this->proposal->replicate(['uuid', 'dpc_code']);
        $other->dpc_code = 'DPC-2026-HE-0098';
        $other->save();
        $this->seedOneOfficer($other, 98, 'Other DPC officer', $this->urCategoryId(), [2025 => 'क']);
        $this->assertEveryRouteRefused($this->chairman, $this->row(98, $other), $other);

        // The proceeding approved: editing closes.
        DB::table('dpc_proceedings')->where('id', $this->proceeding->id)->update(['status' => DpcProceedingStatus::ResultApproved->value]);
        $this->assertEveryRouteRefused($this->chairman, $this->row());
    }

    public function test_only_the_current_allottee_not_a_seat_flag_or_a_former_allottee(): void
    {
        $this->atTheSitting();

        // The allotment returned: nobody presides, nobody edits.
        DpcAllotment::query()->where('dpc_proposal_id', $this->proposal->id)->update(['returned_at' => now()]);
        $this->assertEveryRouteRefused($this->memberOne, $this->row());

        // Never allotted, the seat's flag says DPC Chairman: still no edit.
        DpcAllotment::query()->where('dpc_proposal_id', $this->proposal->id)->delete();
        DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->where('user_id', $this->memberOne->id)->update(['is_dpc_chairman' => 1]);
        $this->assertFalse(app(ChairmanSittingEdit::class)->mayEdit($this->proposal->fresh(), $this->memberOne));
        $this->assertEveryRouteRefused($this->memberOne, $this->row());
    }

    public function test_another_dpcs_candidates_are_out_of_reach(): void
    {
        $this->atTheSitting();
        $other = $this->proposal->replicate(['uuid', 'dpc_code']);
        $other->dpc_code = 'DPC-2026-HE-0099';
        $other->save();
        $this->seedOneOfficer($other, 99, 'Other DPC officer', $this->urCategoryId(), [2025 => 'क']);

        // Another DPC's officer, through that DPC's own URL: refused.
        $this->assertEveryRouteRefused($this->memberOne, $this->row(99, $other), $other);

        // Through this DPC's URL: no such officer here.
        $this->actingAs($this->memberOne)->get(route('dpc-proposals.employees.show', [$this->proposal, 99]))->assertNotFound();
        $this->put(route('dpc-proposals.employees.basic', [$this->proposal, 99]), self::BASIC)->assertNotFound();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->atTheSitting();

        foreach ($this->recordRoutes($this->row()) as [$method, $url, $payload]) {
            $this->assertTrue(in_array($this->{$method}($url, $payload)->getStatusCode(), [302, 401], true), $url);
            auth()->logout();
        }
    }

    public function test_the_department_keeps_its_own_access_and_no_more(): void
    {
        $this->atTheSitting();

        // The DPC is with the committee: the AO reads it but no longer writes, the AH likewise.
        $this->actingAs($this->authorizedOfficer)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row()]))->assertOk()
            ->assertDontSee('id="chairman-sitting-edit"', false);
        $this->put(route('dpc-proposals.employees.basic', [$this->proposal, $this->row()]), self::BASIC)->assertForbidden();
        $this->actingAs($this->administrativeHead)->put(route('dpc-proposals.employees.basic', [$this->proposal, $this->row()]), self::BASIC)->assertForbidden();

        // An AO of another department: nothing.
        $stranger = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();
        $this->assertEveryRouteRefused($stranger, $this->row());

        $this->assertSame(0, ActivityTimeline::query()->where('event', ChairmanSittingEdit::EVENT)->count());
    }
}
