<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Domain\Dpc\Services\DpcStageService;
use App\Domain\Proceeding\Services\CalculationGate;
use App\Domain\Proceeding\Services\CommitteeSittingService;
use App\Domain\Proceeding\Services\MemberViewService;
use App\Domain\Shared\Models\ActivityTimeline;
use Illuminate\Support\Carbon;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * The committee members' view of an allotted DPC (2026-11-24): its DPC Chairman sees it
 * once allotted; the members only once the DPC Chairman opens it to them, or once the
 * days the Administrator sets (two when none) pass after the allotment. Calculate is
 * offered only at the sitting, its day from its hour, and to the DPC Chairman alone.
 */
final class MemberViewModeTest extends TestCase
{
    use BuildsProceedings;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProceeding();
        $this->officer(1, 'US1', $this->urCategoryId(), [2021 => 'क', 2022 => 'क', 2023 => 'क', 2024 => 'क', 2025 => 'क']);

        $this->member = User::factory()->create(['name' => 'Seated Member']);
        $this->seatAccountOnCommittee($this->proposal, $this->member);

        // Closed to the members again, as just allotted.
        DpcProposal::query()->whereKey($this->proposal->id)->update(['member_view_allotment_id' => null, 'member_view_enabled_at' => null, 'member_view_enabled_by' => null]);
        $this->proposal->refresh();
    }

    private function allotment(): DpcAllotment
    {
        return DpcAllotment::query()->where('dpc_proposal_id', $this->proposal->id)->current()->sole();
    }

    private function assertMemberSeesNothing(): void
    {
        $this->assertFalse(app(CommitteeSittingService::class)->isSeatedOn($this->proposal->fresh(), $this->member));
        $this->actingAs($this->member)->get(route('sitting.index'))->assertOk()->assertDontSee($this->proposal->dpc_code);
        $this->get(route('sitting.show', $this->proposal))->assertForbidden();
        $this->get(route('dpc-proposals.tabulation-chart', $this->proposal))->assertForbidden();
        $this->assertSame([], array_filter(app(DpcStageService::class)->pendingAt($this->member), fn (array $entry): bool => $entry['proposal']->id === $this->proposal->id));
    }

    public function test_the_members_see_nothing_until_the_dpc_chairman_opens_it(): void
    {
        $this->assertMemberSeesNothing();

        // The DPC Chairman sees it, with the deadline, and opens it.
        $deadline = $this->allotment()->allotted_at->copy()->addDays(2)->format('d-m-Y H:i');
        $this->actingAs($this->memberOne)->get(route('sitting.show', $this->proposal))->assertOk()
            ->assertSee('data-member-view="closed"', false)
            ->assertSee(__('proceeding.member_view.closed', ['date' => $deadline]))
            ->assertSee('id="activate-member-view"', false);

        // A member cannot open it.
        $this->actingAs($this->member)->post(route('sitting.member-view.activate', $this->proposal))->assertForbidden();

        $this->actingAs($this->memberOne)->post(route('sitting.member-view.activate', $this->proposal))->assertSessionHasNoErrors();
        $this->assertTrue(app(MemberViewService::class)->isActive($this->proposal->fresh()));
        $this->assertSame($this->memberOne->id, (int) ActivityTimeline::query()->where('event', MemberViewService::EVENT_ACTIVATED)->sole()->causer_id);
        $this->get(route('sitting.show', $this->proposal))->assertOk()->assertSee('data-member-view="open"', false)->assertDontSee('id="activate-member-view"', false);
        // Not twice.
        $this->post(route('sitting.member-view.activate', $this->proposal))->assertSessionHasErrors('member_view');

        $this->actingAs($this->member)->get(route('sitting.index'))->assertOk()->assertSee($this->proposal->dpc_code);
        $this->get(route('sitting.show', $this->proposal))->assertOk();
    }

    public function test_it_opens_on_its_own_once_the_days_pass_and_is_recorded_once(): void
    {
        $allottedAt = $this->allotment()->allotted_at;
        Carbon::setTestNow($allottedAt->copy()->addDays(2)->subMinute());
        $this->assertMemberSeesNothing();
        $this->artisan('dpc:member-view-auto-activate')->expectsOutputToContain('0 DPC(s)')->assertSuccessful();

        Carbon::setTestNow($allottedAt->copy()->addDays(2));
        $this->assertTrue(app(CommitteeSittingService::class)->isSeatedOn($this->proposal->fresh(), $this->member));
        $this->actingAs($this->member)->get(route('sitting.show', $this->proposal))->assertOk();

        $this->artisan('dpc:member-view-auto-activate')->expectsOutputToContain('1 DPC(s)')->assertSuccessful();
        $this->artisan('dpc:member-view-auto-activate')->expectsOutputToContain('0 DPC(s)')->assertSuccessful();
        $this->assertSame(1, ActivityTimeline::query()->where('event', MemberViewService::EVENT_AUTO)->count());
        $this->actingAs($this->memberOne)->get(route('sitting.show', $this->proposal))->assertOk()->assertDontSee('id="activate-member-view"', false);
    }

    public function test_the_days_are_the_administrators_for_the_committee_view_stage(): void
    {
        WorkflowStageTimeline::query()->updateOrCreate(['stage' => DpcStage::CommitteeView->value], ['days' => 5]);
        $allottedAt = $this->allotment()->allotted_at;

        Carbon::setTestNow($allottedAt->copy()->addDays(3));
        $this->assertFalse(app(MemberViewService::class)->isActive($this->proposal->fresh()));
        Carbon::setTestNow($allottedAt->copy()->addDays(5));
        $this->assertTrue(app(MemberViewService::class)->isActive($this->proposal->fresh()));
    }

    public function test_a_re_allotment_closes_it_to_the_members_again(): void
    {
        $this->actingAs($this->memberOne)->post(route('sitting.member-view.activate', $this->proposal))->assertSessionHasNoErrors();
        $this->assertTrue(app(MemberViewService::class)->isActive($this->proposal->fresh()));

        $this->allotForTest($this->proposal, $this->memberTwo, openToMembers: false);
        $this->assertFalse(app(MemberViewService::class)->isActive($this->proposal->fresh()));
        $this->assertTrue(app(MemberViewService::class)->mayActivate($this->proposal->fresh(), $this->memberTwo));
    }

    public function test_calculate_is_offered_only_at_the_sitting_and_to_the_dpc_chairman(): void
    {
        $this->openToMembers($this->proposal);
        $this->everyMemberAgrees($this->proposal);
        $gate = app(CalculationGate::class);
        $schedule = app(CommitteeSittingService::class)->schedule($this->proposal);
        $this->assertNotNull($schedule);

        // The day before: not yet.
        Carbon::setTestNow($schedule->scheduledFor()->copy()->subDay());
        $this->assertSame(__('dpc.calculation_gate.awaiting_sitting', ['when' => $schedule->scheduledFor()->format('d-m-Y H:i')]), $gate->refusal($this->proposal->fresh(), $this->memberOne));
        $this->actingAs($this->memberOne)->get(route('sitting.show', $this->proposal))->assertOk()->assertDontSee('id="committee-calculate"', false);

        // An hour before, on the day: not yet.
        Carbon::setTestNow($schedule->scheduledFor()->copy()->subHour());
        $this->assertNotNull($gate->refusal($this->proposal->fresh(), $this->memberOne));

        // From its hour: the DPC Chairman's alone.
        Carbon::setTestNow($schedule->scheduledFor()->copy()->addMinute());
        $this->assertNull($gate->refusal($this->proposal->fresh(), $this->memberOne));
        $this->get(route('sitting.show', $this->proposal))->assertOk()->assertSee('id="committee-calculate"', false);
        $this->assertSame(__('dpc.calculation_gate.only_dpc_chairman'), $gate->refusal($this->proposal->fresh(), $this->member));
        $this->actingAs($this->member)->get(route('sitting.show', $this->proposal))->assertOk()->assertDontSee('id="committee-calculate"', false);
    }
}
