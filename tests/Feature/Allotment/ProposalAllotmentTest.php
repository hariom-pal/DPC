<?php

declare(strict_types=1);

namespace Tests\Feature\Allotment;

use App\Domain\Allotment\Exceptions\AllotmentRefused;
use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\PayScale;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Services\DashboardSummaryService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Support\PptPromotionRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\OpensPreDpcs;
use Tests\Concerns\PrescribesQualifications;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Per-proposal allotment to an AO and an AH (business decisions A1–A5,
 * 2026-10-26; docs/plans/PROPOSAL-ALLOTMENT-PLAN.md).
 */
class ProposalAllotmentTest extends TestCase
{
    use OpensPreDpcs, RaisesDpcProposals {
        OpensPreDpcs::allot insteadof RaisesDpcProposals;
        OpensPreDpcs::departmentHasOfficer insteadof RaisesDpcProposals;
        OpensPreDpcs::firstOfficer insteadof RaisesDpcProposals;
    }
    use PrescribesQualifications;
    use RefreshDatabase;

    private Department $department;

    private User $departmentAdmin;

    private User $ao;

    private User $otherAo;

    private User $ah;

    private User $otherAh;

    private DepartmentPost $fromPost;

    private DepartmentPost $toPost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        // A DPC is raised only under a Class Master promotion rule.
        PptPromotionRules::record(['I-I' => 35, 'II-I' => 31, 'II-II' => 27, 'default' => 25], tribalBonus: 2, superSeatPoints: 9);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'registered']);
        $this->departmentAdmin = User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create();
        $this->ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create(['name' => 'Allotted AO']);
        $this->otherAo = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create(['name' => 'Other AO']);
        $this->ah = User::factory()->assignedTo($this->department, Role::AdministrativeHead)->create(['name' => 'Allotted AH']);
        $this->otherAh = User::factory()->assignedTo($this->department, Role::AdministrativeHead)->create(['name' => 'Other AH']);

        $scale = PayScale::query()->create(['code' => 'L12', 'label' => 'Level-12', 'status' => MasterStatus::Active->value]);
        $this->fromPost = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Assistant Professor', 'class_level' => 'I',
            'promotion_duration_years' => 5, 'status' => MasterStatus::Active->value,
        ]);
        $this->toPost = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Professor', 'class_level' => 'I',
            'pay_scale_id' => $scale->id, 'status' => MasterStatus::Active->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'dpc_year' => 2026,
            'promotion_to_post_id' => $this->toPost->id,
            'direct_recruitment_pct' => 0,
            'promotion_recruitment_pct' => 100,
            'promotion_sources' => [[
                'promotion_from_post_id' => $this->fromPost->id, 'promotion_percentage' => 100,
                'min_experience_years' => 5, 'experience_unit' => 'years', 'qualification_ids' => $this->qualificationIds(),
            ]],
            'government_order_number' => 'GO/2026/HE/1',
            'effective_from' => '2026-01-01',
        ];
    }

    private function opened(): PreDpcProposal
    {
        $this->storePreDpc($this->ao, $this->payload(), $this->ah)->assertSessionHasNoErrors();

        return PreDpcProposal::query()->sole();
    }

    /**
     * The Department Admin opens the Pre-DPC and fills it in; nobody sees it until
     * it is allotted (2026-10-28).
     */
    private function filledByTheDepartmentAdmin(): PreDpcProposal
    {
        $this->actingAs($this->departmentAdmin)->post(route('department.allotments.pre-dpc.store'), [
            'dpc_year' => 2026, 'promotion_to_post_id' => $this->toPost->id,
        ])->assertRedirect(route('pre-dpc.edit', PreDpcProposal::query()->sole()));
        $proposal = PreDpcProposal::query()->sole();

        $this->put(route('pre-dpc.update', $proposal), $this->payload())
            ->assertRedirect(route('department.allotments.pre-dpc.edit', $proposal));

        return $proposal->fresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function allotAs(PreDpcProposal $proposal, User $ao, User $ah, array $extra = []): TestResponse
    {
        return $this->actingAs($this->departmentAdmin)->put(route('department.allotments.pre-dpc.update', $proposal), ['ao_user_id' => $ao->id, 'ah_user_id' => $ah->id] + $extra);
    }

    // ------------------------------------------------------------ A1: opening

    public function test_the_department_admin_opens_fills_in_and_then_allots_a_pre_dpc(): void
    {
        $this->actingAs($this->departmentAdmin)->post(route('department.allotments.pre-dpc.store'), [
            'dpc_year' => 2026, 'promotion_to_post_id' => $this->toPost->id,
        ]);
        $proposal = PreDpcProposal::query()->sole();
        $this->assertSame(PreDpcStatus::Draft, $proposal->status);
        $this->assertSame($this->departmentAdmin->id, $proposal->prepared_by);
        $this->assertNull($proposal->currentAllotment, 'Nobody is allotted until the last step.');

        // Not complete yet: the last step waits.
        $this->allotAs($proposal, $this->ao, $this->ah)->assertSessionHasErrors('ao_user_id');
        $this->actingAs($this->ao)->get(route('pre-dpc.show', $proposal))->assertForbidden();

        // Filled in, then allotted.
        $this->actingAs($this->departmentAdmin)->put(route('pre-dpc.update', $proposal), $this->payload())
            ->assertRedirect(route('department.allotments.pre-dpc.edit', $proposal));
        $this->allotAs($proposal, $this->ao, $this->ah)->assertSessionHasNoErrors();

        $proposal->refresh();
        $this->assertSame([$this->ao->id, $this->ah->id], [$proposal->currentAllotment->ao_user_id, $proposal->currentAllotment->ah_user_id]);
        $this->assertSame($this->departmentAdmin->id, $proposal->currentAllotment->allotted_by);
        $this->assertDatabaseHas('activity_timelines', ['event' => 'proposal.allotted', 'causer_id' => $this->departmentAdmin->id]);
        $this->actingAs($this->ao)->get(route('pre-dpc.show', $proposal))->assertOk();
    }

    /**
     * Any department's active officer may be allotted (2026-10-28); an inactive
     * account or one of the Commission's may not.
     */
    public function test_only_active_non_commission_officers_can_be_allotted(): void
    {
        $proposal = $this->filledByTheDepartmentAdmin();
        $inactiveAo = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->inactive()->create();
        $member = User::factory()->create();
        $member->assignRole(Role::Member->value);

        foreach ([$inactiveAo, $member] as $ao) {
            $this->allotAs($proposal, $ao, $this->ah)->assertSessionHasErrors('ao_user_id');
        }
        $this->assertNull($proposal->fresh()->currentAllotment);

        $elsewhere = Department::query()->create(['code' => 'SE', 'name' => 'School Education Department', 'status' => 'registered']);
        $foreignAo = User::factory()->assignedTo($elsewhere, Role::AuthorizedOfficer)->create();
        $this->allotAs($proposal, $foreignAo, $this->ah)->assertSessionHasNoErrors();
    }

    /**
     * One officer is never both the AO and the AH of a proposal (Phase 0,
     * 2026-10-27) — refused by the form and by the service behind it.
     */
    public function test_the_same_officer_cannot_be_both_ao_and_ah(): void
    {
        $proposal = $this->filledByTheDepartmentAdmin();
        $this->allotAs($proposal, $this->ao, $this->ao)->assertSessionHasErrors(['ah_user_id' => __('allotments.errors.same_officer')]);

        $this->expectException(AllotmentRefused::class);
        app(ProposalAllotmentService::class)->allot($proposal, $this->ao, $this->ao, $this->departmentAdmin, 'x');
    }

    public function test_the_department_admin_cannot_reach_another_departments_proposal(): void
    {
        $proposal = $this->opened();
        $elsewhere = Department::query()->create(['code' => 'SE', 'name' => 'School Education Department', 'status' => 'registered']);
        $foreignAdmin = User::factory()->assignedTo($elsewhere, Role::DepartmentAdmin)->create();

        $this->actingAs($foreignAdmin)->get(route('department.allotments.pre-dpc.edit', $proposal))->assertForbidden();
        $this->actingAs($foreignAdmin)->put(route('department.allotments.pre-dpc.update', $proposal), [
            'ao_user_id' => $this->otherAo->id, 'ah_user_id' => $this->ah->id, 'reason' => 'x',
        ])->assertForbidden();
        $this->actingAs($foreignAdmin)->get(route('department.allotments.index', ['view' => 'pre-dpc']))->assertOk()->assertDontSee($proposal->pre_dpc_number);
    }

    /**
     * The Department Admin builds the whole Pre-DPC (2026-10-28) but never signs:
     * submitting is the allotted AO's, approving the allotted AH's.
     */
    public function test_the_department_admin_fills_in_the_pre_dpc_but_never_submits_or_approves(): void
    {
        $proposal = $this->opened();

        $this->actingAs($this->departmentAdmin)->get(route('pre-dpc.show', $proposal))->assertOk();
        $this->get(route('pre-dpc.edit', $proposal))->assertOk();
        $this->post(route('pre-dpc.submit', $proposal))->assertSessionHasErrors('workflow'); // refused: only the allotted AO signs and submits
        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->departmentAdmin)->post(route('pre-dpc.approve', $proposal))->assertSessionHasErrors('workflow');
        $this->get(route('pre-dpc.edit', $proposal))->assertRedirect(route('pre-dpc.show', $proposal));
        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);

        // Another department's Department Admin sees nothing of it.
        $elsewhere = Department::query()->create(['code' => 'OTH', 'name' => 'Other Department', 'status' => 'registered']);
        $foreignAdmin = User::factory()->assignedTo($elsewhere, Role::DepartmentAdmin)->create();
        $this->actingAs($foreignAdmin)->get(route('pre-dpc.show', $proposal))->assertForbidden();
    }

    // --------------------------------------------- A3: only the allotted see

    public function test_only_the_allotted_ao_prepares_and_only_the_allotted_ah_approves(): void
    {
        $proposal = $this->opened();

        // The other officers of the same department do not see it at all.
        foreach ([$this->otherAo, $this->otherAh] as $officer) {
            $this->actingAs($officer)->get(route('pre-dpc.index'))->assertOk()->assertDontSee($proposal->pre_dpc_number);
            $this->actingAs($officer)->get(route('pre-dpc.show', $proposal))->assertForbidden();
        }
        $this->actingAs($this->otherAo)->get(route('pre-dpc.edit', $proposal))->assertForbidden();
        $this->actingAs($this->otherAo)->post(route('pre-dpc.submit', $proposal))->assertForbidden();

        $this->actingAs($this->ao)->get(route('pre-dpc.index'))->assertSee($proposal->pre_dpc_number);
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal))->assertSessionHasNoErrors();

        $this->actingAs($this->otherAh)->post(route('pre-dpc.approve', $proposal))->assertForbidden();
        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);

        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal))->assertSessionHasNoErrors();
        $this->assertSame(PreDpcStatus::Approved, $proposal->fresh()->status);
    }

    public function test_the_ahs_dashboard_counts_only_what_is_allotted_to_them(): void
    {
        $proposal = $this->opened();
        $this->raiseFromApproved($proposal);
        DpcProposal::query()->sole()->forceFill(['status' => 'submitted'])->save();

        $awaiting = fn (User $user): int => (int) collect(app(DashboardSummaryService::class)->for($user))
            ->flatMap(fn (array $group): array => $group['cards'])
            ->firstWhere('label', 'Awaiting your approval')['value'];

        $this->assertSame(1, $awaiting($this->ah));
        $this->assertSame(0, $awaiting($this->otherAh));
    }

    // ----------------------------------------- A4: nobody works unallotted

    public function test_a_proposal_with_no_allotment_waits_for_the_department_admin(): void
    {
        $proposal = $this->opened();
        ProposalAllotment::query()->toBase()->delete(); // as a proposal from before allotment existed

        foreach ([$this->ao, $this->ah, $this->otherAo] as $officer) {
            $this->actingAs($officer)->get(route('pre-dpc.show', $proposal))->assertForbidden();
        }

        $this->actingAs($this->departmentAdmin)->get(route('department.allotments.index', ['view' => 'pre-dpc']))
            ->assertOk()->assertSee('data-allotment-pending', false);

        // Allotting it needs no reason: there is nothing to replace.
        $this->actingAs($this->departmentAdmin)->put(route('department.allotments.pre-dpc.update', $proposal), [
            'ao_user_id' => $this->ao->id, 'ah_user_id' => $this->ah->id,
        ])->assertRedirect(route('department.allotments.index', ['view' => 'pre-dpc']));
        $this->actingAs($this->ao)->get(route('pre-dpc.show', $proposal))->assertOk();
    }

    // ------------------------------------------------ A5: reassignment

    public function test_reassignment_needs_a_reason_moves_the_work_and_keeps_the_history(): void
    {
        $proposal = $this->opened();

        $this->actingAs($this->departmentAdmin)->put(route('department.allotments.pre-dpc.update', $proposal), [
            'ao_user_id' => $this->otherAo->id, 'ah_user_id' => $this->ah->id,
        ])->assertSessionHasErrors('ao_user_id');
        $this->assertSame($this->ao->id, $proposal->fresh()->currentAllotment->ao_user_id, 'Refused without a reason.');

        $this->actingAs($this->departmentAdmin)->put(route('department.allotments.pre-dpc.update', $proposal), [
            'ao_user_id' => $this->otherAo->id, 'ah_user_id' => $this->otherAh->id, 'reason' => 'Officer transferred',
        ])->assertRedirect(route('department.allotments.index', ['view' => 'pre-dpc']));

        // The work moves at once.
        $this->actingAs($this->ao)->get(route('pre-dpc.edit', $proposal))->assertForbidden();
        $this->actingAs($this->otherAo)->get(route('pre-dpc.edit', $proposal))->assertOk();

        // Nothing is rewritten: the first allotment is ended, not changed.
        $rows = ProposalAllotment::query()->where('pre_dpc_proposal_id', $proposal->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->ended_at);
        $this->assertSame($this->departmentAdmin->id, $rows[0]->ended_by);
        $this->assertSame([$this->ao->id, $this->ah->id], [$rows[0]->ao_user_id, $rows[0]->ah_user_id]);
        $this->assertNull($rows[1]->ended_at);
        $this->assertSame('Officer transferred', $rows[1]->reason);
        $this->assertDatabaseHas('activity_timelines', ['event' => 'proposal.reallotted', 'causer_id' => $this->departmentAdmin->id]);

        $this->actingAs($this->departmentAdmin)->get(route('department.allotments.pre-dpc.history', $proposal))
            ->assertOk()->assertSee('Allotted AO')->assertSee('Other AO')->assertSee('Officer transferred');
    }

    public function test_an_allotment_is_never_edited_or_deleted(): void
    {
        $allotment = $this->opened()->currentAllotment;

        $this->expectException(\LogicException::class);
        $allotment->forceFill(['ao_user_id' => $this->otherAo->id])->save();
    }

    public function test_the_same_allotment_again_is_refused(): void
    {
        $proposal = $this->opened();

        $this->expectException(AllotmentRefused::class);
        app(ProposalAllotmentService::class)->allot($proposal, $this->ao, $this->ah, $this->departmentAdmin, 'again');
    }

    // ------------------------------------------- A2: DPC follows its Pre-DPC

    private function raiseFromApproved(PreDpcProposal $proposal): DpcProposal
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal));

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), $this->dpcStorePayload($proposal->fresh(), [
            'promotion_from_post_id' => $this->fromPost->id,
        ]))->assertSessionHasNoErrors();

        return DpcProposal::query()->sole();
    }

    public function test_a_dpc_inherits_its_pre_dpc_allotment_and_is_reassigned_on_its_own(): void
    {
        $proposal = $this->opened();

        // Only the allotted AO may raise a DPC from it.
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal));
        $this->actingAs($this->otherAo)->post(route('dpc-proposals.store'), $this->dpcStorePayload($proposal->fresh(), [
            'promotion_from_post_id' => $this->fromPost->id,
        ]))->assertForbidden();
        $this->assertSame(0, DpcProposal::query()->count());

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), $this->dpcStorePayload($proposal->fresh(), [
            'promotion_from_post_id' => $this->fromPost->id,
        ]));
        $dpc = DpcProposal::query()->sole();
        $this->assertSame([$this->ao->id, $this->ah->id], [$dpc->currentAllotment->ao_user_id, $dpc->currentAllotment->ah_user_id]);

        $this->actingAs($this->departmentAdmin)->put(route('department.allotments.dpc.update', $dpc), [
            'ao_user_id' => $this->otherAo->id, 'ah_user_id' => $this->otherAh->id, 'reason' => 'A parallel DPC for another team',
        ])->assertRedirect(route('department.allotments.index'));

        $this->assertSame($this->otherAo->id, $dpc->fresh()->currentAllotment->ao_user_id);
        $this->assertSame($this->ao->id, $proposal->fresh()->currentAllotment->ao_user_id, 'The Pre-DPC keeps its own allotment.');
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $dpc))->assertForbidden();
        $this->actingAs($this->otherAo)->get(route('dpc-proposals.show', $dpc))->assertOk();
    }

    /**
     * Every DPC screen reached by the proposal alone refuses an officer it is not
     * allotted to — a missed raw role check anywhere would show up here.
     */
    public function test_every_dpc_screen_refuses_an_officer_it_is_not_allotted_to(): void
    {
        $dpc = $this->raiseFromApproved($this->opened());

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route): bool => str_starts_with((string) $route->getName(), 'dpc-proposals.')
                && in_array('GET', $route->methods(), true)
                && $route->parameterNames() === ['dpcProposal']);

        $this->assertGreaterThan(10, $routes->count());

        foreach ($routes as $route) {
            $url = route((string) $route->getName(), $dpc);

            foreach ([$this->otherAo, $this->otherAh] as $officer) {
                $status = $this->actingAs($officer)->get($url)->status();
                $this->assertContains($status, [403, 404], "{$route->getName()} answered {$status} to a non-allotted officer.");
            }
        }
    }

    // ------------------------------------------------------- notifications

    public function test_notifications_go_to_the_allotted_officers_only(): void
    {
        $proposal = $this->opened();
        Event::fake([WorkflowTransitioned::class]);

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));

        Event::assertDispatched(WorkflowTransitioned::class, fn (WorkflowTransitioned $event): bool => $event->event === 'submit'
            && $event->notifyUserIds === [$this->ah->id]
            && ! in_array(Role::AdministrativeHead->value, $event->notifyRoles, true));
    }
}
