<?php

declare(strict_types=1);

namespace Tests\Feature\CandidateVerification;

use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\Authentication\Models\User;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Services\CandidateSignIn;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Signature\Models\SignatureAttempt;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\CandidateVerification\Concerns\BuildsVerification;
use Tests\TestCase;

/**
 * The Candidate Portal as one portal (2026-11-20): mobile, OTP, then eSign before it
 * opens; every DPC of the candidate's, a DPC with no verification of theirs read only;
 * its stage and, once published, the result; and no password sign-in for a candidate.
 */
class CandidatePortalAccessTest extends TestCase
{
    use BuildsVerification;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 11:30:00');
        $this->bootVerification(days: 10);
    }

    private function toSignIn(int $sn): void
    {
        auth()->logout();
        $this->post(route('candidate-verification.code.send'), ['mobile' => $this->mobileOf($sn)]);
        $this->post(route('candidate-verification.verify'), ['code' => '123456'])->assertRedirect(route('candidate-verification.sign-in'));
    }

    public function test_the_otp_screen_resends_a_code_and_lets_the_number_be_changed(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();
        auth()->logout();
        $this->post(route('candidate-verification.code.send'), ['mobile' => $this->mobileOf(1)]);
        $this->get(route('candidate-verification.code'))->assertOk()
            ->assertSee(__('candidate.verification.login.sent_to', ['tail' => substr($this->mobileOf(1), -4)]))
            ->assertSee(route('candidate-verification.code.resend'), false)
            ->assertSee(__('candidate.verification.login.change_number'));

        // At once: the cooldown, no second code.
        $this->post(route('candidate-verification.code.resend'))->assertRedirect(route('candidate-verification.code'))
            ->assertSessionHas('status', __('candidate.verification.login.wait'));
        $this->assertSame(1, OtpVerification::query()->count());

        // After it: a new code, which signs in.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->post(route('candidate-verification.code.resend'))->assertSessionHas('status', __('candidate.verification.login.sent'));
        $this->assertSame(2, OtpVerification::query()->count());
        $this->post(route('candidate-verification.verify'), ['code' => '123456'])->assertRedirect(route('candidate-verification.sign-in'));

        // Without a number entered first: back to the start.
        $this->app['session.store']->flush();
        $this->post(route('candidate-verification.code.resend'))->assertRedirect(route('candidate-verification.login'));
    }

    public function test_the_portal_opens_only_after_the_esign_matches_the_name(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();
        $this->toSignIn(1);

        // Before the eSign: nothing of the portal.
        $this->get(route('candidate-verification.dashboard'))->assertRedirect(route('candidate-verification.login'));
        $this->get(route('candidate-verification.sign-in'))->assertOk()->assertSee(__('candidate.verification.login.esign_heading'));

        // A name that is not theirs: refused, logged, still outside.
        $this->post(route('candidate-verification.sign-in.store'), ['decision' => 'sign', 'name' => 'Somebody Else'])
            ->assertRedirect(route('candidate-verification.sign-in'))->assertSessionHasErrors('name');
        $this->assertSame([['action' => CandidateSignIn::SIGN_ACTION, 'outcome' => 'name_mismatch']], SignatureAttempt::query()->where('action', CandidateSignIn::SIGN_ACTION)->get(['action', 'outcome'])->toArray());
        $this->get(route('candidate-verification.dashboard'))->assertRedirect(route('candidate-verification.login'));

        // Their own name: the portal opens, and the sign-in is a verified eSign.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->post(route('candidate-verification.sign-in.store'), ['decision' => 'sign', 'name' => $this->row(1)->name])->assertRedirect(route('candidate-verification.dashboard'));
        $this->get(route('candidate-verification.dashboard'))->assertOk()->assertSee($this->row(1)->name);
        $this->assertSame(1, SignatureAttempt::query()->where('action', CandidateSignIn::SIGN_ACTION)->where('outcome', SignatureAttempt::OUTCOME_VERIFIED)->count());
        $this->assertLessThanOrEqual(20, strlen(CandidateSignIn::SIGN_ACTION));
    }

    public function test_a_cancelled_esign_or_no_account_keeps_the_portal_shut(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();
        $this->toSignIn(1);
        $this->post(route('candidate-verification.sign-in.store'), ['decision' => 'cancel'])->assertRedirect(route('candidate-verification.login'));
        $this->get(route('candidate-verification.sign-in'))->assertRedirect(route('candidate-verification.login'));

        // No candidate account to sign with: the department must see to it.
        User::query()->where('employee_number', $this->row(2)->employee_number)->delete();
        Carbon::setTestNow(now()->addMinutes(2));
        $this->toSignIn(2);
        $this->post(route('candidate-verification.sign-in.store'), ['decision' => 'sign', 'name' => $this->row(2)->name])
            ->assertSessionHasErrors(['name' => __('candidate.verification.errors.no_account')]);
        $this->get(route('candidate-verification.dashboard'))->assertRedirect(route('candidate-verification.login'));
    }

    public function test_a_dpc_without_a_verification_of_theirs_is_shown_read_only(): void
    {
        // Approved before the verification existed (or with it off): straight to the Secretary.
        config(['dpc.candidate_verification.enabled' => false]);
        $this->approveByAh()->assertSessionHasNoErrors();
        config(['dpc.candidate_verification.enabled' => true]);
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);

        $this->signIn(1);
        $own = $this->row(1);
        $this->get(route('candidate-verification.dashboard'))->assertOk()
            ->assertSee('data-row="'.$own->id.'"', false)
            ->assertDontSee('data-participant=', false)
            ->assertSee(route('candidate-verification.record', [$own->id, 'seniority']), false)
            ->assertSee(__('candidate.verification.dashboard.read_only'))
            ->assertSee(__('candidate.verification.dashboard.group_other'))
            ->assertDontSee(__('candidate.verification.dashboard.group_open'))
            ->assertSee($this->proposal->fresh()->status->label());

        foreach (['seniority', 'cr', 'integrity', 'ipr'] as $section) {
            $this->get(route('candidate-verification.record', [$own->id, $section]))->assertOk()
                ->assertDontSee(__('candidate.verification.actions.agree_next'))
                ->assertDontSee(__('candidate.verification.actions.raise'));
        }
        $this->get(route('candidate-verification.record', [$this->row(2)->id, 'cr']))->assertNotFound();
        $this->post(url('candidate-verification/record/'.$own->id.'/section/cr'))->assertStatus(405);
    }

    public function test_the_result_appears_once_published_and_never_a_mark_or_the_committee(): void
    {
        config(['dpc.candidate_verification.enabled' => false]);
        $this->approveByAh()->assertSessionHasNoErrors();
        $this->proposal->forceFill(['status' => DpcProposalStatus::Approved->value, 'forwarded_at' => now()])->saveQuietly();

        DpcCommitteeMember::query()->create([
            'dpc_proposal_id' => $this->proposal->id, 'name' => 'Hon. Committee Chairman', 'designation' => 'Principal Secretary',
            'capacity' => CommitteeCapacity::CgpscChairman->value, 'sort_order' => 1, 'active_flag' => 1,
        ]);
        $run = DpcCalculationRun::query()->create([
            'dpc_proposal_id' => $this->proposal->id, 'rule_version' => 'test', 'configuration_snapshot' => [],
            'proposal_version' => str_repeat('0', 64), 'application_version' => 'test', 'git_commit_hash' => 'test', 'zone' => [], 'executed_at' => now(),
        ]);
        DpcCalculationResult::query()->create([
            'dpc_calculation_run_id' => $run->id, 'sn' => 1, 'officer_name' => $this->row(1)->name, 'employee_number' => $this->row(1)->employee_number,
            'reservation_category_code' => 'UR', 'score_breakdown' => ['total' => '71.50'], 'verdict' => ['eligible' => true],
            'super_seat' => ['applies' => false], 'remark' => 'RECOMMENDED', 'list_type' => ListType::Main->value,
        ]);
        $member = User::factory()->create();
        $member->assignRole(Role::Member->value);
        $proceeding = DpcProceeding::query()->create([
            'dpc_proposal_id' => $this->proposal->id, 'status' => DpcProceedingStatus::ResultApproved->value,
            'conducted_by' => $member->id, 'conducted_by_name' => $member->name, 'proposal_version' => str_repeat('c', 64), 'opened_at' => now(),
        ]);
        $proceeding->forceFill(['final_calculation_run_id' => $run->id])->saveQuietly();
        $document = [
            'uuid' => (string) Str::uuid(), 'dpc_proceeding_id' => $proceeding->id, 'type' => 'proceedings', 'status' => 'generated', 'proceeding_uuid' => (string) Str::uuid(),
            'proposal_uuid' => (string) $this->proposal->uuid, 'generator_version' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ];
        if (DB::getSchemaBuilder()->hasColumn('dpc_proceeding_documents', 'generator_commit_hash')) {
            $document['generator_commit_hash'] = 'test';
        }
        $documentId = DB::table('dpc_proceeding_documents')->insertGetId($document);

        $this->signIn(1);
        // Generated, not published: no outcome to state.
        $this->get(route('candidate-verification.dashboard'))->assertOk()
            ->assertSee(__('candidate.verification.dashboard.result_pending'))
            ->assertDontSee(__('candidate.verification.dashboard.promoted').'</strong>', false);

        DB::table('dpc_proceeding_documents')->where('id', $documentId)->update(['published_at' => now()]);
        $this->get(route('candidate-verification.dashboard'))->assertOk()
            ->assertSee(__('candidate.verification.dashboard.promoted').'</strong>', false)
            ->assertDontSee('Hon. Committee Chairman')
            ->assertDontSee('Principal Secretary')
            ->assertDontSee('71.50');
    }

    public function test_the_grievances_reach_the_ah_only_after_the_deadline_and_the_candidates_form_closes(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();
        $this->verifyAndSubmit(1, ['cr']);
        $uuid = $this->participant(1)->uuid;
        $grievance = Grievance::query()->sole();

        // Submitted: the candidate's grievance screen is gone, the section reads only.
        $this->get(route('candidate-verification.grievance', [$uuid, 'seniority']))->assertRedirect(route('candidate-verification.section', [$uuid, 'seniority']));

        // During the period: the AH sees no grievance, not even how many.
        auth()->logout();
        $this->actingAs($this->ah)->get(route('dpc-proposals.show', $this->proposal))->assertOk()
            ->assertDontSee('id="grievances-link"', false)
            ->assertSee(__('candidate.grievance.review.summary_open', ['submitted' => 1, 'total' => $this->cycle()->participant_count]));
        $this->get(route('dpc-proposals.grievances.index', $this->proposal))->assertNotFound();
        $this->get(route('dpc-proposals.grievances.document', [$this->proposal, $grievance]))->assertNotFound();

        // After the deadline: every grievance, together.
        Carbon::setTestNow($this->cycle()->deadline_at->copy()->addSecond());
        $this->artisan('dpc:candidate-verification-deadlines')->assertSuccessful();
        $this->get(route('dpc-proposals.show', $this->proposal))->assertOk()->assertSee('id="grievances-link"', false);
        $this->get(route('dpc-proposals.grievances.index', $this->proposal))->assertOk()->assertSee($grievance->remark);

        // And the candidate who did nothing finds no grievance screen either.
        auth()->logout();
        $this->signIn(2);
        $this->get(route('candidate-verification.grievance', [$this->participant(2)->uuid, 'cr']))->assertRedirect(route('candidate-verification.section', [$this->participant(2)->uuid, 'cr']));
    }

    public function test_a_signed_in_candidate_is_offered_their_dashboard_never_the_department_login(): void
    {
        $this->approveByAh()->assertSessionHasNoErrors();

        // Signed out: the department's login, as before.
        auth()->logout();
        $this->get('/')->assertOk()->assertSee(__('ui.department_login'))->assertDontSee('id="candidate-dashboard-link"', false);

        $this->signIn(1);
        $this->get('/')->assertOk()
            ->assertSee('id="candidate-dashboard-link"', false)
            ->assertSee('id="candidate-hero-dashboard"', false)
            ->assertSee(route('candidate-verification.dashboard'), false)
            // No way to the department's login (the Process Flow still names that step, as text).
            ->assertDontSee('href="'.route('login').'"', false)
            ->assertDontSee(__('landing.quick_actions.administrator.title'));
        // The header on the portal's own pages too.
        $this->get(route('candidate-verification.dashboard'))->assertOk()
            ->assertSee('id="candidate-dashboard-link"', false)
            ->assertDontSee('href="'.route('login').'"', false);

        // Signed out of the portal: the department's login again.
        $this->post(route('candidate-verification.logout'));
        $this->get('/')->assertOk()->assertSee(__('ui.department_login'))->assertDontSee('id="candidate-dashboard-link"', false);
    }

    public function test_a_candidate_account_cannot_sign_in_with_a_password_on_the_staff_login(): void
    {
        $candidate = User::query()->where('employee_number', $this->row(1)->employee_number)->sole();
        $candidate->forceFill(['password' => bcrypt('known-password'), 'must_change_password' => false])->save();
        auth()->logout();

        $this->post(route('login.store'), ['login' => $candidate->employee_number, 'password' => 'known-password'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => __('authui.login.candidate_use_portal')]);
        $this->assertGuest();
        $this->get('/my-dpc')->assertNotFound();
    }
}
