<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\PreviousDpcBasis;
use App\Domain\Dpc\Enums\ProposalVerificationItem;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcProposalVerification;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\ProposalVerificationService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Rule 1.1 of the DPC Understanding Document — the department's pre-submission
 * confirmations, and the submission gate that requires them.
 */
class ProposalVerificationTest extends TestCase
{
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake((string) config('dpc.documents.disk', 'local'));

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create();

        $postFrom = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($postFrom->id, $postTo->id)),
        );
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
    }

    /**
     * Every section complete except Rule 1.1 — the gate switched off while the
     * fixture fills the sections, so the helper does not confirm on our behalf.
     */
    private function sectionsCompleteButUnverified(): DpcProposal
    {
        config(['dpc.proposal_verification.enforced' => false]);
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        config(['dpc.proposal_verification.enforced' => true]);

        return $this->proposal->refresh();
    }

    private function service(): ProposalVerificationService
    {
        return app(ProposalVerificationService::class);
    }

    // ------------------------------------------------------------- drafts

    public function test_a_draft_is_created_without_any_confirmation(): void
    {
        $this->assertSame(DpcProposalStatus::Draft, $this->proposal->status);
        $this->assertSame(0, DpcProposalVerification::query()->count());

        $this->actingAs($this->ao)->get(route('dpc-proposals.verification', $this->proposal))
            ->assertOk()
            ->assertSee(__('dpc.verification.items.sanctioned_strength.statement'));
    }

    public function test_a_draft_can_be_saved_with_the_checklist_incomplete(): void
    {
        $this->sectionsCompleteButUnverified();

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.verification.store', [$this->proposal, 'sanctioned_strength']), ['confirm' => '1'])
            ->assertRedirect(route('dpc-proposals.verification', $this->proposal))
            ->assertSessionHasNoErrors();

        // One of four: still a draft, still editable, other sections still saveable.
        $this->proposal->refresh();
        $this->assertSame(DpcProposalStatus::Draft, $this->proposal->status);
        $this->assertFalse($this->service()->isComplete($this->proposal));

        $this->actingAs($this->ao)
            ->put(route('dpc-proposals.update', $this->proposal), ['dpc_type_id' => $this->proposal->dpc_type_id, 'legal_stay' => '0'])
            ->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------- submission

    public function test_submission_is_blocked_while_the_checklist_is_incomplete(): void
    {
        $this->sectionsCompleteButUnverified();

        $this->assertSame(
            ['Pre-submission Verification (Rule 1.1)'],
            app(DpcProposalCompletenessService::class)->missingSections($this->proposal),
        );

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.submit', $this->proposal))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(DpcProposalStatus::Draft, $this->proposal->refresh()->status);
    }

    public function test_the_gate_holds_at_the_service_layer_not_only_in_the_browser(): void
    {
        $this->sectionsCompleteButUnverified();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Pre-submission Verification (Rule 1.1)');

        app(DpcProposalService::class)->submit($this->proposal, $this->ao, request());
    }

    public function test_submission_is_allowed_once_all_four_are_confirmed(): void
    {
        $this->sectionsCompleteButUnverified();

        foreach (['sanctioned_strength', 'recruitment_rule', 'seniority_list'] as $item) {
            $this->actingAs($this->ao)
                ->post(route('dpc-proposals.verification.store', [$this->proposal, $item]), ['confirm' => '1'])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.verification.store', [$this->proposal, 'previous_dpc_reconciliation']), [
                'confirm' => '1',
                'previous_dpc_basis' => 'none',
                'remarks' => 'First DPC for this post since the cadre was created.',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.submit', $this->proposal))
            ->assertSessionHasNoErrors();

        $this->assertSame(DpcProposalStatus::Submitted, $this->proposal->refresh()->status);
    }

    public function test_the_existing_section_checks_still_hold_beside_rule_one_one(): void
    {
        // Rule 1.1 cannot be confirmed against data that is not there.
        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.verification.store', [$this->proposal, 'seniority_list']), ['confirm' => '1'])
            ->assertSessionHasErrors('workflow');

        $missing = app(DpcProposalCompletenessService::class)->missingSections($this->proposal);

        $this->assertContains('DPC Committee (S15)', $missing);
        $this->assertContains('Seniority List (S17)', $missing);
        $this->assertContains('Pre-submission Verification (Rule 1.1)', $missing);
    }

    public function test_the_confirmation_box_must_be_ticked(): void
    {
        $this->sectionsCompleteButUnverified();

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.verification.store', [$this->proposal, 'sanctioned_strength']), [])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(0, DpcProposalVerification::query()->count());
    }

    // ------------------------------------------------- previous DPC reference

    public function test_no_previous_dpc_needs_an_explanation(): void
    {
        $this->sectionsCompleteButUnverified();

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.verification.store', [$this->proposal, 'previous_dpc_reconciliation']), [
                'confirm' => '1',
                'previous_dpc_basis' => 'none',
            ])
            ->assertSessionHasErrors('remarks');
    }

    public function test_an_offline_previous_dpc_needs_its_reference_date_and_minutes(): void
    {
        $this->sectionsCompleteButUnverified();
        $route = route('dpc-proposals.verification.store', [$this->proposal, 'previous_dpc_reconciliation']);

        $this->actingAs($this->ao)->post($route, ['confirm' => '1', 'previous_dpc_basis' => 'offline'])
            ->assertSessionHasErrors(['previous_dpc_reference', 'previous_dpc_held_on', 'minutes']);

        $this->actingAs($this->ao)->post($route, [
            'confirm' => '1',
            'previous_dpc_basis' => 'offline',
            'previous_dpc_reference' => 'GAD/DPC/2019/14',
            'previous_dpc_held_on' => '2019-11-20',
            'minutes' => UploadedFile::fake()->create('minutes-2019.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $row = DpcProposalVerification::query()->sole();
        $this->assertSame(PreviousDpcBasis::Offline, $row->previous_dpc_basis);
        $this->assertSame('GAD/DPC/2019/14', $row->previous_dpc_reference);
        $this->assertSame('2019-11-20', $row->previous_dpc_held_on->toDateString());
        $this->assertSame(64, strlen((string) $row->sha256));
        Storage::disk((string) $row->disk)->assertExists((string) $row->path);

        // The minutes are readable by the Administrative Head, not by another department.
        $this->actingAs($this->ah)->get(route('dpc-proposals.verification.minutes', [$this->proposal, $row]))->assertOk();
        $outsider = User::factory()->assignedTo(
            Department::query()->create(['code' => 'PWD', 'name' => 'Public Works Department', 'status' => 'active'])->id,
            Role::AuthorizedOfficer,
        )->create();
        $this->actingAs($outsider)->get(route('dpc-proposals.verification.minutes', [$this->proposal, $row]))->assertForbidden();
    }

    public function test_an_in_system_previous_dpc_must_be_an_approved_dpc_for_the_same_post(): void
    {
        $this->sectionsCompleteButUnverified();
        $route = route('dpc-proposals.verification.store', [$this->proposal, 'previous_dpc_reconciliation']);

        // The proposal itself is not its own previous DPC.
        $this->actingAs($this->ao)->post($route, [
            'confirm' => '1',
            'previous_dpc_basis' => 'in_system',
            'previous_dpc_proposal_id' => (string) $this->proposal->id,
        ])->assertSessionHasErrors('workflow');

        $previous = $this->proposal->replicate();
        $previous->forceFill([
            'uuid' => (string) Str::uuid(),
            'dpc_code' => 'DPC-2024-HE-0001',
            'status' => DpcProposalStatus::Approved->value,
            'approved_at' => now()->subYear(),
        ])->save();

        $this->actingAs($this->ao)->post($route, [
            'confirm' => '1',
            'previous_dpc_basis' => 'in_system',
            'previous_dpc_proposal_id' => (string) $previous->id,
        ])->assertSessionHasNoErrors();

        $row = DpcProposalVerification::query()->sole();
        $this->assertSame($previous->id, $row->previous_dpc_proposal_id);
        $this->assertSame('DPC-2024-HE-0001', $row->previous_dpc_reference);
    }

    // ------------------------------------------------------- authorization

    public function test_only_the_departments_authorized_officer_may_confirm(): void
    {
        $this->sectionsCompleteButUnverified();
        $route = route('dpc-proposals.verification.store', [$this->proposal, 'sanctioned_strength']);

        $this->actingAs($this->ah)->post($route, ['confirm' => '1'])->assertForbidden();

        $outsider = User::factory()->assignedTo(
            Department::query()->create(['code' => 'PWD', 'name' => 'Public Works Department', 'status' => 'active'])->id,
            Role::AuthorizedOfficer,
        )->create();
        $this->actingAs($outsider)->post($route, ['confirm' => '1'])->assertForbidden();
        $this->actingAs($outsider)->get(route('dpc-proposals.verification', $this->proposal))->assertForbidden();

        // The Administrative Head may read what was confirmed.
        $this->actingAs($this->ah)->get(route('dpc-proposals.verification', $this->proposal))->assertOk();

        $this->assertSame(0, DpcProposalVerification::query()->count());
    }

    public function test_a_submitted_proposal_cannot_be_reconfirmed_or_withdrawn(): void
    {
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();
        $before = DpcProposalVerification::query()->count();

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.verification.store', [$this->proposal, 'sanctioned_strength']), ['confirm' => '1'])
            ->assertForbidden();
        $this->actingAs($this->ao)
            ->delete(route('dpc-proposals.verification.destroy', [$this->proposal, 'sanctioned_strength']))
            ->assertForbidden();

        $this->expectException(WorkflowException::class);
        $this->service()->confirm($this->proposal->refresh(), ProposalVerificationItem::SanctionedStrength, $this->ao);

        $this->assertSame($before, DpcProposalVerification::query()->count());
    }

    // --------------------------------------------------------------- audit

    public function test_every_confirmation_and_withdrawal_is_kept_as_history(): void
    {
        $this->sectionsCompleteButUnverified();
        $item = ProposalVerificationItem::SanctionedStrength;

        $this->actingAs($this->ao)->post(route('dpc-proposals.verification.store', [$this->proposal, $item->value]), [
            'confirm' => '1',
            'remarks' => 'Checked against GAD order 12/2024.',
        ]);
        $this->actingAs($this->ao)->delete(route('dpc-proposals.verification.destroy', [$this->proposal, $item->value]))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->ao)->post(route('dpc-proposals.verification.store', [$this->proposal, $item->value]), ['confirm' => '1']);

        $history = $this->service()->history($this->proposal->refresh());

        $this->assertSame(['confirmed', 'withdrawn', 'confirmed'], $history->pluck('status')->all());
        $first = $history->first();
        $this->assertSame($item, $first->item);
        $this->assertSame($this->ao->id, $first->verified_by);
        $this->assertNotNull($first->verified_at);
        $this->assertSame($this->proposal->revision_number, $first->revision_number);
        $this->assertSame('Checked against GAD order 12/2024.', $first->remarks);
        $this->assertSame(
            ['promotion_to_post_id' => $this->proposal->promotion_to_post_id, 'sanctioned' => $first->basis['sanctioned']],
            $first->basis,
        );

        $events = ActivityTimeline::query()
            ->where('subject_id', $this->proposal->id)
            ->whereIn('event', ['dpc_proposal.verification_confirmed', 'dpc_proposal.verification_withdrawn'])
            ->orderBy('id')
            ->pluck('event')
            ->all();
        $this->assertSame([
            'dpc_proposal.verification_confirmed',
            'dpc_proposal.verification_withdrawn',
            'dpc_proposal.verification_confirmed',
        ], $events);

        $this->assertGreaterThan(0, $first->audits()->count(), 'Each statement is also on the audit trail.');

        $this->actingAs($this->ao)->get(route('dpc-proposals.verification', $this->proposal))
            ->assertOk()
            ->assertSee('Checked against GAD order 12/2024.');
    }

    public function test_withdrawing_what_was_never_confirmed_is_refused(): void
    {
        $this->actingAs($this->ao)
            ->delete(route('dpc-proposals.verification.destroy', [$this->proposal, 'recruitment_rule']))
            ->assertSessionHasErrors('workflow');

        $this->assertSame(0, DpcProposalVerification::query()->count());
    }

    public function test_an_unknown_item_is_not_found(): void
    {
        $this->actingAs($this->ao)
            ->delete(route('dpc-proposals.verification.destroy', [$this->proposal, 'reservation_roster']))
            ->assertNotFound();
    }

    // ------------------------------------- existing working / sanctioned strength

    public function test_confirming_changes_no_vacancy_figure(): void
    {
        $this->sectionsCompleteButUnverified();
        $summary = app(VacancyCalculationService::class);
        $before = collect($summary->summary($this->proposal))->except(['zone', 'breakdown'])->all();

        $this->confirmRuleOneOne($this->proposal, $this->ao);

        $this->assertSame($before, collect($summary->summary($this->proposal->refresh()))->except(['zone', 'breakdown'])->all());
    }

    public function test_a_changed_sanctioned_strength_needs_reconfirmation(): void
    {
        $this->sectionsCompleteButUnverified();
        $this->confirmRuleOneOne($this->proposal, $this->ao);
        $this->assertTrue($this->service()->isComplete($this->proposal));

        // The Department Post Mapping changes after the department confirmed it.
        $post = $this->proposal->promotionToPost;
        $post->forceFill(['sanctioned_strength' => (int) $post->sanctioned_strength + 1])->save();

        $status = collect($this->service()->status($this->proposal->refresh()))
            ->firstWhere('item', ProposalVerificationItem::SanctionedStrength);

        $this->assertTrue($status['confirmed']);
        $this->assertTrue($status['stale']);
        $this->assertFalse($status['complete']);
        $this->assertStringContainsString(
            'data changed since it was confirmed',
            implode(' ', $this->service()->outstanding($this->proposal)),
        );
    }

    public function test_a_changed_working_strength_needs_reconfirmation(): void
    {
        $this->sectionsCompleteButUnverified();
        $this->confirmRuleOneOne($this->proposal, $this->ao);

        // A vacant post on the chart is now held by an officer: one more working.
        $row = $this->proposal->workingOfficers()->where('status', DpcWorkingOfficer::STATUS_VACANT)->firstOrFail();
        $row->forceFill(['status' => DpcWorkingOfficer::STATUS_WORKING, 'name' => 'Seated Officer'])->save();
        $this->assertNotSame(
            $this->proposal->verifications()->latest('id')->first()?->basis,
            $this->service()->basisFor($this->proposal->refresh(), ProposalVerificationItem::PreviousDpcReconciliation),
        );

        $status = collect($this->service()->status($this->proposal->refresh()))
            ->firstWhere('item', ProposalVerificationItem::PreviousDpcReconciliation);

        $this->assertTrue($status['stale']);
    }

    public function test_sanctioned_strength_cannot_be_confirmed_when_none_is_recorded(): void
    {
        $this->proposal->promotionToPost->forceFill(['sanctioned_strength' => null])->save();

        $this->expectException(WorkflowException::class);
        $this->service()->confirm($this->proposal->refresh(), ProposalVerificationItem::SanctionedStrength, $this->ao);
    }

    // ------------------------------------------------------ revisions & history

    public function test_a_return_opens_a_revision_that_must_be_confirmed_again(): void
    {
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();
        $this->actingAs($this->ah)->post(route('dpc-proposals.return', $this->proposal), ['return_remarks' => 'Recheck the gradation list.']);

        $this->proposal->refresh();
        $this->assertFalse($this->service()->isComplete($this->proposal));

        // The earlier revision's confirmations are still on record.
        $this->assertSame(4, $this->proposal->verifications()->where('revision_number', 1)->count());
        $this->assertSame(2, $this->proposal->revision_number);
    }

    public function test_a_proposal_submitted_before_rule_one_one_is_shown_as_not_recorded(): void
    {
        $this->makeProposalSubmittable($this->proposal, $this->ao);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();

        // A proposal from before this table existed carries no confirmation at all.
        DpcProposalVerification::query()->delete();
        $this->proposal->forceFill(['status' => DpcProposalStatus::Approved->value, 'approved_at' => now()])->save();

        $this->assertFalse($this->service()->isComplete($this->proposal->refresh()));

        $this->actingAs($this->ah)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee(__('dpc.verification.not_recorded_legacy'));
        $this->actingAs($this->ah)->get(route('dpc-proposals.review', [$this->proposal, 5]))
            ->assertOk()
            ->assertSee(__('dpc.verification.not_recorded_legacy'));

        // Viewing never writes a confirmation.
        $this->assertSame(0, DpcProposalVerification::query()->count());
    }

    public function test_the_gate_can_be_stood_down_by_configuration(): void
    {
        $this->sectionsCompleteButUnverified();
        config(['dpc.proposal_verification.enforced' => false]);

        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();
        $this->assertSame(DpcProposalStatus::Submitted, $this->proposal->refresh()->status);
    }
}
