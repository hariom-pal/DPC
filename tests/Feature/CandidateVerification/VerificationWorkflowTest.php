<?php

declare(strict_types=1);

namespace Tests\Feature\CandidateVerification;

use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Enums\CycleStatus;
use App\Domain\CandidateVerification\Enums\GrievanceStatus;
use App\Domain\CandidateVerification\Enums\ParticipantStatus;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Models\VerificationCycle;
use App\Domain\CandidateVerification\Models\VerificationNotification;
use App\Domain\CandidateVerification\Models\VerificationSnapshot;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Domain\Master\Models\IntegrityOption;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Domain\Signature\Models\Signature;
use App\Domain\Signature\Models\SignatureAttempt;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\CandidateVerification\Concerns\BuildsVerification;
use Tests\TestCase;

/**
 * Candidate Verification & Grievance (2026-11-19), the DPC's side: opened by the AH's
 * approval on the Administrator's days, closed by its deadline, routed to the
 * Secretary or to the AH's review; the AH decides each grievance, opens only the
 * candidate's section for the AO, and the DPC reaches the Secretary when all are decided.
 */
class VerificationWorkflowTest extends TestCase
{
    use BuildsVerification;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 11:30:00');
        $this->bootVerification(days: 10);
    }

    public function test_the_ahs_approval_opens_the_verification_on_the_administrators_days(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();

        $this->assertSame(DpcProposalStatus::CandidateVerification, $this->proposal->fresh()->status);
        $cycle = $this->cycle();
        $this->assertSame(10, $cycle->duration_days);
        $this->assertSame('2026-11-19 11:30:00', $cycle->approved_at->format('Y-m-d H:i:s'));
        // The end of the tenth day, in the application's timezone.
        $this->assertSame('2026-11-29 23:59:59', $cycle->deadline_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'));
        $this->assertSame($this->proposal->evaluationRows()->count(), $cycle->participant_count);
        $this->assertSame($cycle->participant_count, $cycle->participants()->where('status', ParticipantStatus::NotStarted->value)->count());
        $this->assertSame('approve_to_verification', WorkflowTransition::query()->where('subject_id', $this->proposal->id)->latest('id')->value('event'));

        // Every candidate told by SMS and e-mail, each recorded sent — not merely queued.
        foreach (['sms', 'email'] as $channel) {
            $sent = VerificationNotification::query()->where('kind', 'opened')->where('channel', $channel)->get();
            $this->assertCount($cycle->participant_count, $sent, $channel);
            $this->assertSame(['sent'], $sent->pluck('status')->unique()->values()->all(), $channel);
        }

        // The Administrator's later change does not move an open cycle's deadline.
        WorkflowStageTimeline::query()->where('stage', DpcStage::CandidateVerification->value)->update(['days' => 3]);
        $this->assertSame('2026-11-29', $this->cycle()->deadline_at->timezone(config('app.timezone'))->format('Y-m-d'));

        // The Secretary does not have it.
        $this->assertNotSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);
    }

    public function test_without_the_days_set_the_approval_is_refused_before_anything_is_signed(): void
    {
        WorkflowStageTimeline::query()->where('stage', DpcStage::CandidateVerification->value)->update(['days' => null]);

        $this->approveByAh()->assertSessionHasErrors();

        $this->assertSame(DpcProposalStatus::Submitted, $this->proposal->fresh()->status);
        $this->assertSame(0, VerificationCycle::query()->count());
    }

    public function test_with_no_grievance_the_deadline_sends_it_to_the_secretary_once(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();
        $this->verifyAndSubmit(1);

        // Not before the deadline.
        $this->artisan('dpc:candidate-verification-deadlines')->assertSuccessful();
        $this->assertSame(DpcProposalStatus::CandidateVerification, $this->proposal->fresh()->status);

        Carbon::setTestNow('2026-11-30 00:00:01');
        $this->artisan('dpc:candidate-verification-deadlines')->expectsOutputToContain('1 candidate verification(s) closed')->assertSuccessful();

        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);
        $this->assertSame(CycleStatus::Completed, $this->cycle()->status);
        $this->assertSame(ParticipantStatus::VerifiedAgreed, $this->participant(1)->status);
        $this->assertSame(ParticipantStatus::NoResponse, $this->participant(2)->status, 'No response is never agreement.');
        $this->assertSame(1, WorkflowTransition::query()->where('event', 'verification_closed')->whereNull('actor_id')->count());

        // Again: nothing.
        $this->artisan('dpc:candidate-verification-deadlines')->expectsOutputToContain('0 candidate verification(s) closed')->assertSuccessful();
        $this->assertSame(1, WorkflowTransition::query()->where('event', 'verification_closed')->count());

        // A candidate after the deadline: refused.
        $this->signIn(2);
        $this->post(route('candidate-verification.agree', [$this->participant(2)->uuid, 'seniority']))->assertSessionHasErrors('verification');
    }

    public function test_grievances_go_to_the_ah_who_decides_each_and_opens_only_the_section_for_the_ao(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();
        $this->verifyAndSubmit(1, ['cr', 'ipr']);
        Carbon::setTestNow('2026-11-30 00:00:01');
        $this->artisan('dpc:candidate-verification-deadlines')->assertSuccessful();

        $this->assertSame(DpcProposalStatus::GrievanceReview, $this->proposal->fresh()->status);
        $this->assertSame(2, $this->cycle()->grievance_count);
        $this->assertSame(1, VerificationNotification::query()->where('kind', 'grievance_review')->where('recipient', 'user:'.$this->ah->id)->where('channel', 'email')->count());

        // The Secretary cannot have it yet.
        $this->assertNotSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);

        // The AH's dashboard, candidate-wise, filterable by section.
        $cr = Grievance::query()->where('section', 'cr')->sole();
        $ipr = Grievance::query()->where('section', 'ipr')->sole();
        $this->actingAs($this->ah)->get(route('dpc-proposals.grievances.index', $this->proposal))->assertOk()
            ->assertSee('data-grievance="'.$cr->uuid.'"', false)->assertSee('data-grievance="'.$ipr->uuid.'"', false);
        $this->get(route('dpc-proposals.grievances.index', [$this->proposal, 'section' => 'ipr']))
            ->assertSee('data-grievance="'.$ipr->uuid.'"', false)->assertDontSee('data-grievance="'.$cr->uuid.'"', false);
        $this->get(route('dpc-proposals.grievances.document', [$this->proposal, $cr]))->assertOk();

        // The AO cannot decide; a reason is required.
        $this->actingAs($this->ao)->post(route('dpc-proposals.grievances.decide', [$this->proposal, $cr]), ['decision' => 'not_upheld', 'text' => 'x'])->assertSessionHasErrors('grievance');
        $this->assertNull(session('esign.pending'), 'No eSign is begun for a step that cannot run.');
        $this->actingAs($this->ah)->post(route('dpc-proposals.grievances.decide', [$this->proposal, $cr]), ['decision' => 'not_upheld', 'text' => ''])->assertSessionHasErrors('text');

        // IPR: not upheld — the AH eSigns, it closes with its reason (2026-11-24).
        $this->travel(2)->minutes();
        $this->notUphold($ipr, 'The filing date is right.')->assertSessionHasNoErrors();
        $this->assertSame(GrievanceStatus::Resolved, $ipr->fresh()->status);
        $this->assertSame('not_upheld', $ipr->fresh()->decision?->value);
        $this->assertSame(1, Signature::query()->where('signable_type', $ipr->getMorphClass())->where('signable_id', $ipr->id)->count());
        $this->assertSame(DpcProposalStatus::GrievanceReview, $this->proposal->fresh()->status, 'One still open.');

        // CR: upheld — only this candidate's CR section opens for the AO.
        $row = $this->row(1);
        $other = $this->row(2);
        $this->assertFalse($this->ao->can('updateEmployeeSection', [$this->proposal->fresh(), 'cr', $row]));
        $this->uphold($cr, 'Correct the 2024 grade.')->assertSessionHasNoErrors();
        $this->assertSame(GrievanceStatus::SentToAo, $cr->fresh()->status);
        $proposal = $this->proposal->fresh();
        $this->assertTrue($this->ao->can('updateEmployeeSection', [$proposal, 'cr', $row]));
        $this->assertFalse($this->ao->can('updateEmployeeSection', [$proposal, 'basic', $row]), 'Not another section.');
        $this->assertFalse($this->ao->can('updateEmployeeSection', [$proposal, 'cr', $other]), 'Not another candidate.');
        $this->assertFalse($this->ah->can('updateEmployeeSection', [$proposal, 'cr', $row]), 'The AO corrects, not the AH.');
        $this->assertSame(1, VerificationNotification::query()->where('kind', 'correction_assigned')->where('recipient', 'user:'.$this->ao->id)->where('channel', 'email')->count());
        // The AO's own menu shows the DPC; the AO's page the task.
        $this->actingAs($this->ao)->get(route('grievances.corrections'))->assertOk()->assertSee('data-grievance-corrections="'.$this->proposal->uuid.'"', false);
        $this->get(route('dpc-proposals.grievances.index', $this->proposal))->assertOk()->assertSee('data-ao-task', false)->assertSee('data-submit-candidate', false);

        // The AO corrects through Employee Details, then sends it back: the section closes again.
        $year = (int) $row->crGrades()->max('year');
        $this->actingAs($this->ao)->post(route('dpc-proposals.employees.cr.save', [$proposal, $row]), [
            'year' => $year, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => 'क+',
            'integrity_option_id' => (int) IntegrityOption::query()->where('code', 'certified_good')->value('id'),
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->post(route('dpc-proposals.employees.cr.save', [$proposal, $other]), ['year' => $year, 'put_up' => '1', 'has_grading' => '0'])->assertForbidden();
        $this->submitCorrections(1, 'Grade corrected from the CR file.')->assertSessionHasNoErrors();
        $this->assertFalse($this->ao->can('updateEmployeeSection', [$this->proposal->fresh(), 'cr', $row]));
        $this->assertSame(GrievanceStatus::ReturnedFromAo, $cr->fresh()->status, 'A correction never decides the grievance.');

        // The signed submission stays; the corrected record is a new version; the candidate is told.
        $this->assertSame(['signed_submission', 'corrected_after_grievance'], VerificationSnapshot::query()->where('dpc_verification_participant_id', $this->participant(1)->id)->orderBy('version')->pluck('reason')->all());
        // Each version's hash checks against the copy the database holds.
        foreach (VerificationSnapshot::query()->where('dpc_verification_participant_id', $this->participant(1)->id)->get() as $version) {
            $this->assertSame($version->sha256, VerificationSnapshot::hashOf($version->version === 1 ? $version->snapshot : (array) $version->snapshot['record']));
        }
        $this->assertNotNull($this->participant(1)->snapshot_sha256);
        // Not yet the candidate's to read as done (2026-11-24): no message, no notice, until the AH decides.
        $this->assertSame(0, VerificationNotification::query()->where('kind', 'record_corrected')->count());
        $this->signIn(1);
        $this->get(route('candidate-verification.section', [$this->participant(1)->uuid, 'cr']))->assertOk()->assertDontSee('id="corrected-notice"', false);
        auth()->logout();

        // The AH approves the candidate's correction with the eSign: the last one — the DPC goes to the Secretary.
        $this->actingAs($this->ah)->get(route('dpc-proposals.grievances.index', $this->proposal))->assertSee('data-approve-candidate', false);
        $this->travel(2)->minutes();
        $this->approveCorrections(1, 'Corrected.')->assertSessionHasNoErrors();
        $this->assertSame(GrievanceStatus::Resolved, $cr->fresh()->status);
        $this->assertSame('upheld', $cr->fresh()->decision?->value);
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);
        $this->assertSame(CycleStatus::Completed, $this->cycle()->status);
        $this->assertSame(1, WorkflowTransition::query()->where('event', 'grievances_resolved')->where('actor_id', $this->ah->id)->count());
        // MySQL's signature_attempts.action holds 20 characters; SQLite does not enforce it (UAT 2026-11-25).
        foreach (SignatureAttempt::query()->pluck('action') as $action) {
            $this->assertLessThanOrEqual(20, strlen((string) $action), "eSign action '{$action}' is too long for signature_attempts.action");
        }
        $this->assertSame(2, VerificationNotification::query()->where('kind', 'grievance_resolved')->where('channel', 'sms')->count());
        // Decided: now the candidate hears of the correction, and sees it.
        $this->assertSame(1, VerificationNotification::query()->where('kind', 'record_corrected')->where('channel', 'sms')->count());
        auth()->logout();
        // Still signed in to the portal from above.
        $this->get(route('candidate-verification.section', [$this->participant(1)->uuid, 'cr']))->assertOk()->assertSee('id="corrected-notice"', false);
    }

    public function test_a_dpc_approved_again_after_a_return_goes_straight_to_the_secretary(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-11-30 00:00:01');
        $this->artisan('dpc:candidate-verification-deadlines')->assertSuccessful();
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);

        // Returned by the Secretary, resubmitted, approved again: one verification per DPC.
        $this->proposal->forceFill(['status' => DpcProposalStatus::Submitted->value])->save();
        $this->approveByAh()->assertSessionHasNoErrors();
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);
        $this->assertSame(1, VerificationCycle::query()->count());
    }

    public function test_only_the_dpcs_own_officers_read_or_act_on_its_grievances(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();
        $this->verifyAndSubmit(1, ['cr']);
        Carbon::setTestNow('2026-11-30 00:00:01');
        $this->artisan('dpc:candidate-verification-deadlines')->assertSuccessful();
        $grievance = Grievance::query()->sole();

        $otherDepartment = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $outsiderAh = User::factory()->assignedTo($otherDepartment->id, Role::AdministrativeHead)->create();
        $this->actingAs($outsiderAh)->get(route('dpc-proposals.grievances.index', $this->proposal))->assertForbidden();
        $this->actingAs($outsiderAh)->get(route('dpc-proposals.grievances.document', [$this->proposal, $grievance]))->assertForbidden();
        $this->actingAs($outsiderAh)->post(route('dpc-proposals.grievances.decide', [$this->proposal, $grievance]), ['decision' => 'upheld', 'text' => 'x'])->assertSessionHasErrors('grievance');
        $this->notUphold($grievance, 'x', $outsiderAh)->assertSessionHasErrors('grievance');
        $this->actingAs($outsiderAh)->post(route('dpc-proposals.grievances.approve-candidate', [$this->proposal, $this->participant(1)]), ['remark' => 'x'])->assertSessionHasErrors('grievance');
        $this->assertSame(GrievanceStatus::Submitted, $grievance->fresh()->status);

        auth()->logout();
        $this->get(route('dpc-proposals.grievances.index', $this->proposal))->assertRedirect();
    }
}
