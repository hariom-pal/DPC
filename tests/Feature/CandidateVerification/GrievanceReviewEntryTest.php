<?php

declare(strict_types=1);

namespace Tests\Feature\CandidateVerification;

use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Enums\CycleStatus;
use App\Domain\CandidateVerification\Enums\GrievanceStatus;
use App\Domain\CandidateVerification\Enums\VerificationSection;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Models\GrievanceCorrection;
use App\Domain\CandidateVerification\Models\VerificationDecision;
use App\Domain\CandidateVerification\Services\CandidateRecordView;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\CandidateVerification\Concerns\BuildsVerification;
use Tests\TestCase;

/**
 * Grievances, 2026-11-22: several on one section (one for each CR year that is wrong),
 * the AH's own entry point listing their DPCs' grievances candidate by candidate, and
 * the verification closing as soon as every candidate has submitted.
 */
class GrievanceReviewEntryTest extends TestCase
{
    use BuildsVerification;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 11:30:00');
        $this->bootVerification(days: 10);
        $this->approveByAh()->assertSessionHasNoErrors();
    }

    public function test_a_candidate_raises_several_grievances_on_one_section_and_changes_or_removes_each(): void
    {
        $this->signIn(1);
        $uuid = $this->participant(1)->uuid;
        $years = app(CandidateRecordView::class)->yearsFor($this->row(1), VerificationSection::Cr);
        $this->assertGreaterThanOrEqual(2, count($years));

        $this->grievance($uuid, 'cr', ['related_year' => $years[0], 'remark' => 'First year wrong.'])->assertSessionHasNoErrors();
        $this->grievance($uuid, 'cr', ['related_year' => $years[1], 'remark' => 'Second year wrong.'])->assertSessionHasNoErrors();
        $this->assertSame(2, Grievance::query()->where('section', 'cr')->count());

        [$first, $second] = Grievance::query()->orderBy('id')->get()->all();
        $this->get(route('candidate-verification.section', [$uuid, 'cr']))->assertOk()
            ->assertSee('data-grievance="'.$first->uuid.'"', false)->assertSee('data-grievance="'.$second->uuid.'"', false)
            ->assertSee(__('candidate.verification.actions.add_grievance'));
        $this->get(route('candidate-verification.grievance', [$uuid, 'cr', 'grievance' => $first->uuid]))->assertOk()
            ->assertSee('name="grievance" value="'.$first->uuid.'"', false);

        // One changed by its uuid (its PDF kept); the other untouched.
        $this->grievance($uuid, 'cr', ['grievance' => $first->uuid, 'remark' => 'First, corrected.', 'document' => null])->assertSessionHasNoErrors();
        $this->assertSame(['First, corrected.', 'Second year wrong.'], Grievance::query()->orderBy('id')->pluck('remark')->all());
        $this->assertSame($first->path, $first->fresh()->path);

        // One removed: the section still has a grievance.
        $this->delete(route('candidate-verification.grievance.remove', [$uuid, 'cr']), ['grievance' => $second->uuid])->assertSessionHasNoErrors();
        $this->assertSame([$first->uuid], Grievance::query()->pluck('uuid')->all());
        $this->assertSame(VerificationDecision::GRIEVANCE, VerificationDecision::query()->where('section', 'cr')->value('decision'));

        // The last removed: the section is undecided again.
        $this->delete(route('candidate-verification.grievance.remove', [$uuid, 'cr']), ['grievance' => $first->uuid])->assertSessionHasNoErrors();
        $this->assertSame(0, Grievance::query()->count());
        $this->assertNull(VerificationDecision::query()->where('section', 'cr')->value('decision'));
    }

    public function test_the_ah_has_a_grievances_entry_listing_their_dpcs_candidate_by_candidate(): void
    {
        $this->submitAs(1, ['cr', 'ipr']);
        $this->submitAs(2, ['seniority']);
        Carbon::setTestNow('2026-11-30 00:00:01');
        $this->artisan('dpc:candidate-verification-deadlines')->assertSuccessful();

        $this->actingAs($this->ah)->get(route('dashboard'))->assertSee('data-menu="grievances"', false);
        $this->get(route('grievances.queue'))->assertOk()
            ->assertSee('data-grievance-queue="'.$this->proposal->uuid.'"', false)
            ->assertSee(route('dpc-proposals.grievances.index', $this->proposal), false);

        // Candidate-wise: each candidate's grievances under their name.
        $html = (string) $this->get(route('dpc-proposals.grievances.index', $this->proposal))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'data-candidate="'));
        $this->assertStringContainsString(__('candidate.grievance.review.candidate_count', ['count' => 2, 'open' => 2]), $html);

        // Another department's AH sees none of it; the AO has no such entry.
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $outsider = User::factory()->assignedTo($other->id, Role::AdministrativeHead)->create();
        $this->actingAs($outsider)->get(route('grievances.queue'))->assertOk()->assertDontSee('data-grievance-queue="'.$this->proposal->uuid.'"', false);
        $this->actingAs($this->ao)->get(route('grievances.queue'))->assertForbidden();
    }

    public function test_when_every_candidate_has_submitted_the_verification_closes_before_the_deadline(): void
    {
        $sns = $this->proposal->evaluationRows()->orderBy('sn')->pluck('sn')->all();
        $last = array_pop($sns);

        foreach ($sns as $sn) {
            $this->submitAs($sn);
        }
        $this->assertSame(DpcProposalStatus::CandidateVerification, $this->proposal->fresh()->status, 'Not while one is still to submit.');

        $this->submitAs($last, ['cr']);

        $this->assertSame(DpcProposalStatus::GrievanceReview, $this->proposal->fresh()->status);
        $this->assertSame(CycleStatus::GrievanceReview, $this->cycle()->status);
        $transition = WorkflowTransition::query()->where('event', 'verification_grievances')->sole();
        $this->assertSame('all_candidates_responded', $transition->meta['reason']);
        $this->assertSame(__('candidate.verification.auto.verification_grievances_early', ['date' => '19-11-2026', 'count' => 1]), $transition->remarks);
        $this->assertSame(GrievanceStatus::Submitted, Grievance::query()->sole()->status);

        // The deadline later closes nothing again.
        Carbon::setTestNow('2026-11-30 00:00:01');
        $this->artisan('dpc:candidate-verification-deadlines')->expectsOutputToContain('0 candidate verification(s) closed')->assertSuccessful();
    }

    public function test_with_no_grievance_from_anyone_it_goes_to_the_secretary_as_soon_as_all_have_submitted(): void
    {
        foreach ($this->proposal->evaluationRows()->orderBy('sn')->pluck('sn') as $sn) {
            $this->submitAs($sn);
        }

        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);
        $this->assertSame(CycleStatus::Completed, $this->cycle()->status);
        $this->assertSame('all_candidates_responded', WorkflowTransition::query()->where('event', 'verification_closed')->sole()->meta['reason']);
    }

    /** One candidate after another: a minute apart, past the portal's OTP throttle. */
    /**
     * 2026-11-23: the AH sees each grievance as soon as its candidate submits it, and
     * acts on it while the verification is still open — to the AO and back, decided.
     * All decided by the close, the DPC goes straight to the Secretary.
     */
    public function test_the_ah_acts_on_a_grievance_while_the_verification_is_open(): void
    {
        $this->submitAs(1, ['cr']);
        $grievance = Grievance::query()->sole();
        $this->assertSame(CycleStatus::Open, $this->cycle()->status);

        // Another candidate's draft — saved, never submitted with the eSign — is nobody's to see.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->signIn(2);
        $this->grievance($this->participant(2)->uuid, 'cr')->assertSessionHasNoErrors();
        $draft = Grievance::query()->where('status', GrievanceStatus::Draft->value)->sole();
        auth()->logout();

        $this->actingAs($this->ah)->get(route('grievances.queue'))->assertOk()
            ->assertSee('data-grievance-queue="'.$this->proposal->uuid.'"', false)
            ->assertSee('data-verification-open', false);
        $this->get(route('dpc-proposals.grievances.index', $this->proposal))->assertOk()
            ->assertSee('id="verification-still-open"', false)
            ->assertSee('data-grievance="'.$grievance->uuid.'"', false)
            ->assertDontSee('data-grievance="'.$draft->uuid.'"', false);
        $this->get(route('dpc-proposals.grievances.document', [$this->proposal, $draft]))->assertNotFound();
        $this->post(route('dpc-proposals.grievances.resolve', [$this->proposal, $draft]), ['decision' => 'not_upheld', 'resolution_remark' => 'x'])->assertSessionHasErrors();
        $this->assertSame(GrievanceStatus::Draft, $draft->fresh()->status);

        // To the AO, who corrects only that candidate's section, and back.
        $row = $this->row(1);
        $this->post(route('dpc-proposals.grievances.send-to-ao', [$this->proposal, $grievance]), ['instructions' => 'Check the CR.'])->assertSessionHasNoErrors();
        $proposal = $this->proposal->fresh();
        $this->assertTrue($this->ao->can('updateEmployeeSection', [$proposal, 'cr', $row]));
        $this->assertFalse($this->ao->can('updateEmployeeSection', [$proposal, 'cr', $this->row(2)]), 'Not another candidate.');
        $correction = GrievanceCorrection::query()->sole();
        $this->actingAs($this->ao)->post(route('dpc-proposals.grievances.correction', [$proposal, $correction]), ['ao_remarks' => 'Checked.'])->assertSessionHasNoErrors();
        $this->assertFalse($this->ao->can('updateEmployeeSection', [$this->proposal->fresh(), 'cr', $row]));

        $this->actingAs($this->ah)->post(route('dpc-proposals.grievances.resolve', [$this->proposal, $grievance]), ['decision' => 'not_upheld', 'resolution_remark' => 'The record is right.'])->assertSessionHasNoErrors();
        $this->assertSame(GrievanceStatus::Resolved, $grievance->fresh()->status);
        $this->assertSame(DpcProposalStatus::CandidateVerification, $this->proposal->fresh()->status, 'The verification runs on.');

        // Another department's AH cannot act on it.
        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $outsider = User::factory()->assignedTo($other->id, Role::AdministrativeHead)->create();
        $this->actingAs($outsider)->get(route('dpc-proposals.grievances.index', $this->proposal))->assertForbidden();

        // Closed with every grievance decided: to the Secretary, not back to the AH.
        Carbon::setTestNow('2026-11-30 00:00:01');
        $this->artisan('dpc:candidate-verification-deadlines')->assertSuccessful();
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);
        $this->assertSame(CycleStatus::Completed, $this->cycle()->status);
        $this->assertStringContainsString('already decided', (string) WorkflowTransition::query()->where('event', 'verification_closed')->sole()->remarks);
    }

    public function test_a_grievance_still_open_at_the_close_keeps_the_dpc_with_the_ah(): void
    {
        $this->submitAs(1, ['cr', 'ipr']);
        $first = Grievance::query()->orderBy('id')->first();
        $this->actingAs($this->ah)->post(route('dpc-proposals.grievances.resolve', [$this->proposal, $first]), ['decision' => 'not_upheld', 'resolution_remark' => 'Right.'])->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-11-30 00:00:01');
        $this->artisan('dpc:candidate-verification-deadlines')->assertSuccessful();
        $this->assertSame(DpcProposalStatus::GrievanceReview, $this->proposal->fresh()->status);
        $this->assertSame(CycleStatus::GrievanceReview, $this->cycle()->status);
    }

    private function submitAs(int $sn, array $grievances = []): void
    {
        Carbon::setTestNow(now()->addMinutes(2));
        $this->verifyAndSubmit($sn, $grievances);
    }
}
