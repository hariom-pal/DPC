<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Domain\Signature\Models\Signature;
use App\Enums\Role;
use App\Integrations\Contracts\SignatureProvider;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CompletesDpcProposals;
use Tests\TestCase;

/**
 * M4 closeout (Phase 7): Administrative Head review walkthrough (S21–S25),
 * the single-approval workflow, and final workflow validation.
 */
class DpcProposalClosureTest extends TestCase
{
    use CompletesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $this->ah = User::factory()->create(['department_id' => $this->department->id]);
        $this->ah->assignRole(Role::AdministrativeHead->value);

        $postFrom = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $postTo->id,
            'post_from_cadre_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
        ]);
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- validation

    public function test_an_incomplete_proposal_cannot_be_submitted(): void
    {
        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.submit', $this->proposal))
            ->assertRedirect(route('dpc-proposals.show', $this->proposal))
            ->assertSessionHasErrors('workflow');

        $this->assertSame('draft', $this->proposal->refresh()->status->value);
    }

    public function test_the_submit_gate_names_every_missing_section(): void
    {
        $missing = app(DpcProposalCompletenessService::class)->missingSections($this->proposal);

        $this->assertSame([
            'DPC Committee (S15)',
            'Working Officers & Vacancies (S16)',
            'Seniority List (S17)',
            'Evaluation / Tabulation Chart (S18)',
        ], $missing);
    }

    public function test_the_gate_holds_at_the_service_layer_too(): void
    {
        $this->expectException(WorkflowException::class);

        app(DpcProposalService::class)->submit($this->proposal, $this->ao, request());
    }

    public function test_a_vacancy_breakdown_of_zero_is_not_complete(): void
    {
        // A DPC with nothing to fill has no reason to exist.
        $this->makeProposalSubmittable($this->proposal);
        $this->proposal->vacancies()->update(['count' => 0]);

        $this->assertContains(
            'Working Officers & Vacancies (S16)',
            app(DpcProposalCompletenessService::class)->missingSections($this->proposal->refresh()),
        );
    }

    public function test_readiness_checklist_is_shown_to_the_officer_while_editable(): void
    {
        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee('Readiness for Submission')
            ->assertSee('No committee members named yet.');
    }

    public function test_a_complete_proposal_submits(): void
    {
        $this->makeProposalSubmittable($this->proposal);

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.submit', $this->proposal))
            ->assertSessionHasNoErrors();

        $this->assertSame('submitted', $this->proposal->refresh()->status->value);
    }

    // ------------------------------------------------------------------- review

    public function test_ah_can_walk_every_review_step(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $expected = [
            1 => 'Selection of Posts',
            2 => 'DPC Committee',
            3 => 'Officers Under Consideration',
            4 => 'Seniority List',
            5 => 'Tabulation Chart',
        ];

        foreach ($expected as $step => $label) {
            $this->actingAs($this->ah)
                ->get(route('dpc-proposals.review', [$this->proposal, $step]))
                ->assertOk()
                ->assertSee('Approval by Administrative Head')
                ->assertSee($label);
        }
    }

    public function test_the_approval_action_appears_only_on_the_final_review_step(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ah)
            ->get(route('dpc-proposals.review', [$this->proposal, 1]))
            ->assertOk()
            ->assertDontSee('Approve, Sign &amp; Forward to CGPSC', false);

        $this->actingAs($this->ah)
            ->get(route('dpc-proposals.review', [$this->proposal, 5]))
            ->assertOk()
            ->assertSee('Approve, Sign &amp; Forward to CGPSC', false);
    }

    public function test_an_out_of_range_review_step_redirects_to_the_first(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ah)
            ->get(route('dpc-proposals.review', [$this->proposal, 99]))
            ->assertRedirect(route('dpc-proposals.review', [$this->proposal, 1]));
    }

    public function test_another_departments_head_cannot_open_the_review(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->create(['department_id' => $other->id]);
        $intruder->assignRole(Role::AdministrativeHead->value);

        $this->actingAs($intruder)
            ->get(route('dpc-proposals.review', [$this->proposal, 1]))
            ->assertForbidden();
    }

    public function test_the_officer_sees_no_approval_control_on_the_review(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.review', [$this->proposal, 5]))
            ->assertOk()
            ->assertDontSee('Approve, Sign &amp; Forward to CGPSC', false);
    }

    // ------------------------------------------------------- single approval

    public function test_one_approval_covers_every_section_and_signs_the_whole_proposal(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal))->assertSessionHasNoErrors();

        $this->proposal->refresh();
        $this->assertSame('approved', $this->proposal->status->value);
        $this->assertNotNull($this->proposal->dpc_code);
        $this->assertNotNull($this->proposal->forwarded_at);
        $this->assertSame($this->ah->id, $this->proposal->approved_by);

        // Exactly one signature, one approve transition — the single-approval model.
        $this->assertSame(1, Signature::query()
            ->where('signable_type', $this->proposal->getMorphClass())
            ->where('signable_id', $this->proposal->id)
            ->count());

        $this->assertSame(1, WorkflowTransition::query()
            ->where('workflow', 'dpc_proposal')
            ->where('subject_id', $this->proposal->id)
            ->where('event', 'approve')
            ->count());
    }

    public function test_the_signed_document_contains_every_section_that_was_approved(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal));

        $signature = Signature::query()
            ->where('signable_type', $this->proposal->refresh()->getMorphClass())
            ->where('signable_id', $this->proposal->id)
            ->firstOrFail();

        $document = (string) Storage::disk($signature->signed_disk)->get($signature->signed_path);

        // A single approval covers all five sections, so the artefact the AH
        // signed has to contain all five — not just the header.
        $this->assertStringContainsString('SELECTION OF POSTS', $document);
        $this->assertStringContainsString('DPC COMMITTEE', $document);
        $this->assertStringContainsString('Committee Chairman', $document);
        $this->assertStringContainsString('WORKING OFFICERS & VACANCIES', $document);
        $this->assertStringContainsString('FINAL SENIORITY LIST', $document);
        $this->assertStringContainsString('Seniority Officer', $document);
        $this->assertStringContainsString('EVALUATION / TABULATION CHART', $document);
        $this->assertStringContainsString('Evaluated Officer', $document);

        // Mark and Remark are M5 outputs and must not appear in what the
        // department proposes.
        $this->assertStringNotContainsString('MARK:', $document);
    }

    public function test_approval_is_recorded_on_the_timeline_and_the_audit_trail(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal));

        $events = ActivityTimeline::query()
            ->where('subject_type', $this->proposal->getMorphClass())
            ->where('subject_id', $this->proposal->id)
            ->pluck('event');

        // Every section save plus both workflow transitions are on the record.
        foreach ([
            'dpc_proposal.committee_updated',
            'dpc_proposal.seniority_list_updated',
            'dpc_proposal.evaluation_chart_updated',
        ] as $event) {
            $this->assertContains($event, $events, "Missing timeline event {$event}.");
        }

        $this->assertGreaterThan(0, $this->proposal->audits()->count(), 'The proposal must have audit records.');
    }

    public function test_ah_can_return_a_submitted_proposal_from_the_review(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ah)->post(route('dpc-proposals.return', $this->proposal), [
            'return_remarks' => 'Seniority list needs the 2011 entries.',
        ])->assertSessionHasNoErrors();

        $this->proposal->refresh();
        $this->assertSame('returned', $this->proposal->status->value);
        $this->assertSame('Seniority list needs the 2011 entries.', $this->proposal->return_remarks);

        // A returned proposal is editable again, and re-submittable.
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();
        $this->assertSame('submitted', $this->proposal->refresh()->status->value);
    }

    // ------------------------------------------------- DigiSign integration point

    public function test_approval_fails_loudly_when_the_csc_digisign_provider_is_not_yet_wired(): void
    {
        // The CSC eSign API contract has not been supplied by CGPSC, so the
        // production provider is deliberately unimplemented (architect
        // directive: integration point only). It must hard-fail rather than
        // silently fall back to the mock — a promotion order signed by a
        // development stub would be worse than no signature at all.
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        config(['features.csc_digisign' => true]);
        app()->forgetInstance(SignatureProvider::class);

        try {
            $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal));
            $this->fail('Approval must not succeed while the CSC DigiSign provider is unimplemented.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('CSC DigiSign', $e->getMessage());
        }

        // Nothing was half-committed: no signature, no DPC ID, still submitted.
        $this->proposal->refresh();
        $this->assertSame('submitted', $this->proposal->status->value);
        $this->assertNull($this->proposal->dpc_code);
        $this->assertNull($this->proposal->signature_id);
        $this->assertSame(0, Signature::query()
            ->where('signable_type', $this->proposal->getMorphClass())
            ->where('signable_id', $this->proposal->id)
            ->count());
    }

    public function test_production_refuses_to_sign_with_the_mock_provider(): void
    {
        // The rule that matters most in this whole system: a promotion order
        // must never carry a development signature. With CSC DigiSign off,
        // production refuses to approve rather than falling back to the mock.
        //
        // Asserted at the service layer, which is where the guard lives.
        // Driving it over HTTP is not possible: flipping the container to
        // "production" also switches CSRF verification back on, so the request
        // would fail at 419 and prove nothing about signing.
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        // `environment()` reads the container's resolved `env`, fixed at
        // bootstrap — setting config('app.env') afterwards would not change it.
        $this->app['env'] = 'production';
        config(['features.csc_digisign' => false]);

        $proposal = $this->proposal->refresh();

        try {
            app(DpcProposalService::class)->approve($proposal, $this->ah, request());
            $this->fail('Production must refuse to approve without CSC DigiSign enabled.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('CSC DigiSign is not enabled', $e->getMessage());
        }

        $proposal->refresh();
        $this->assertSame('submitted', $proposal->status->value);
        $this->assertNull($proposal->signature_id);
        $this->assertNull($proposal->dpc_code);
        $this->assertSame(0, Signature::query()
            ->where('signable_type', $proposal->getMorphClass())
            ->where('signable_id', $proposal->id)
            ->count());
    }

    public function test_non_production_still_signs_so_uat_stays_demonstrable(): void
    {
        // The counterpart to the rule above: with CSC's API still outstanding,
        // UAT must remain able to demonstrate the full workflow end to end.
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        config(['features.csc_digisign' => false]);

        $this->actingAs($this->ah)
            ->post(route('dpc-proposals.approve', $this->proposal))
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $this->proposal->refresh()->status->value);
        $this->assertNotNull($this->proposal->dpc_code);
    }

    public function test_the_whole_m4_workflow_runs_with_every_integration_disabled(): void
    {
        // The UAT and early-production configuration: nothing connected.
        // No workflow may fail because an integration is switched off.
        config([
            'features.hrms' => false,
            'features.csc_digisign' => false,
            'features.sms_gateway' => false,
            'features.email_gateway' => false,
            'features.s3_storage' => false,
            'features.lgd_sync' => false,
            'features.notifications' => false,
        ]);

        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();
        $this->actingAs($this->ah)->get(route('dpc-proposals.review', [$this->proposal, 5]))->assertOk();
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal))->assertSessionHasNoErrors();

        $this->assertSame('approved', $this->proposal->refresh()->status->value);
        $this->assertNotNull($this->proposal->dpc_code);
    }

    public function test_an_approved_proposal_is_terminal(): void
    {
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal));

        // No further transition, and no section may be edited.
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal))->assertForbidden();
        $this->actingAs($this->ao)->post(route('dpc-proposals.cancel', $this->proposal))->assertForbidden();
        $this->actingAs($this->ao)->get(route('dpc-proposals.seniority-list', $this->proposal))->assertForbidden();
        $this->actingAs($this->ao)->get(route('dpc-proposals.evaluation-chart', $this->proposal))->assertForbidden();
    }
}
