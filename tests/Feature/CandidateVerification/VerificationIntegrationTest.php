<?php

declare(strict_types=1);

namespace Tests\Feature\CandidateVerification;

use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Models\VerificationCycle;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Domain\Dpc\Services\DpcStageService;
use App\Enums\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\CandidateVerification\Concerns\BuildsVerification;
use Tests\TestCase;

/**
 * Candidate Verification & Grievance (2026-11-19) with the rest of the DPC workflow:
 * the Administrator's Workflow Stage Timelines, the AH stage's automatic forward, the
 * Secretary's queue, the stage panel, a legacy DPC, and the database's guarantees.
 */
class VerificationIntegrationTest extends TestCase
{
    use BuildsVerification;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 11:30:00');
        $this->bootVerification(days: 10);
    }

    public function test_the_stage_is_set_on_the_administrators_workflow_stage_timelines(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        $this->actingAs($admin)->get(route('admin.stage-timelines.index'))->assertOk()
            ->assertSee(__('dpc.stage.candidate_verification.label'));
        $this->put(route('admin.stage-timelines.update'), ['days' => ['candidate_verification' => '7']])->assertSessionHasNoErrors();
        $this->assertSame(7, WorkflowStageTimeline::daysFor(DpcStage::CandidateVerification));
        $this->put(route('admin.stage-timelines.update'), ['days' => ['candidate_verification' => '0']])->assertSessionHasErrors();
        // Its expiry is the verification's own routing: no cancel, no forward.
        $this->put(route('admin.stage-timelines.update'), ['on_expiry' => ['candidate_verification' => 'forward']])->assertSessionHasErrors();
    }

    public function test_the_ah_stages_automatic_forward_opens_the_verification_and_never_skips_it(): void
    {
        WorkflowStageTimeline::query()->updateOrCreate(['stage' => DpcStage::Ah->value], ['days' => 2, 'on_expiry' => WorkflowStageTimeline::FORWARD]);

        // No verification days set: no forward at all.
        WorkflowStageTimeline::query()->where('stage', DpcStage::CandidateVerification->value)->update(['days' => null]);
        Carbon::setTestNow('2026-11-25 09:00:00');
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(DpcProposalStatus::Submitted, $this->proposal->fresh()->status);

        // With them: into the verification, not to the Secretary.
        WorkflowStageTimeline::query()->where('stage', DpcStage::CandidateVerification->value)->update(['days' => 10]);
        $this->artisan('dpc:stage-deadlines')->assertSuccessful();
        $this->assertSame(DpcProposalStatus::CandidateVerification, $this->proposal->fresh()->status);
        $this->assertSame($this->proposal->evaluationRows()->count(), $this->cycle()->participant_count);
    }

    public function test_the_secretary_does_not_see_it_until_the_verification_lets_it_through(): void
    {
        $secretary = User::factory()->create();
        $secretary->assignRole(Role::Secretary->value);
        $this->approveByAh()->assertSessionHasNoErrors();

        $this->assertFalse($secretary->can('secretaryApprove', $this->proposal->fresh()));
        $this->actingAs($secretary)->get(route('secretary.index'))->assertOk()->assertDontSee((string) $this->proposal->dpc_code);

        // The stage panel: the candidates' verification with its deadline.
        $stages = app(DpcStageService::class);
        $deadline = $stages->deadline($this->proposal->fresh());
        $this->assertSame(DpcStage::CandidateVerification, $deadline['stage']);
        $this->assertSame('2026-11-29', $deadline['due_at']->timezone(config('app.timezone'))->format('Y-m-d'));
        $this->assertStringContainsString('29-11-2026', implode(' ', $stages->pendingItems($this->proposal->fresh(), DpcStage::CandidateVerification)));

        Carbon::setTestNow('2026-11-30 00:00:01');
        $this->artisan('dpc:candidate-verification-deadlines')->assertSuccessful();
        $this->assertTrue($secretary->can('secretaryApprove', $this->proposal->fresh()));
    }

    public function test_a_dpc_already_with_the_secretary_is_left_as_it_is(): void
    {
        // Before this stage existed: approved straight to the Secretary.
        config(['dpc.candidate_verification.enabled' => false]);
        $this->approveByAh()->assertSessionHasNoErrors();
        config(['dpc.candidate_verification.enabled' => true]);

        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);
        $this->artisan('dpc:candidate-verification-deadlines')->assertSuccessful();
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);
        $this->assertSame(0, VerificationCycle::query()->count());
    }

    public function test_the_database_keeps_one_verification_per_officer_and_allows_several_grievances_a_section(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();
        $participant = $this->participant(1);

        try {
            VerificationParticipant::query()->create(['dpc_verification_cycle_id' => $participant->dpc_verification_cycle_id, 'dpc_evaluation_row_id' => $participant->dpc_evaluation_row_id, 'status' => 'not_started']);
            $this->fail('A second verification of one officer on one DPC was accepted.');
        } catch (QueryException) {
        }

        try {
            VerificationCycle::query()->create(['dpc_proposal_id' => $this->proposal->id, 'status' => 'open', 'approved_at' => now(), 'duration_days' => 1, 'deadline_at' => now()]);
            $this->fail('A second verification of one DPC was accepted.');
        } catch (QueryException) {
        }

        // Several grievances a section are allowed (2026-11-22): one for each wrong year.
        $this->signIn(1);
        $this->grievance($participant->uuid, 'cr')->assertSessionHasNoErrors();
        $this->grievance($participant->uuid, 'cr')->assertSessionHasNoErrors();
        $this->assertSame(2, Grievance::query()->where('dpc_verification_participant_id', $participant->id)->where('section', 'cr')->count());
    }
}
