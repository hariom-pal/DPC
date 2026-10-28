<?php

declare(strict_types=1);

namespace Tests\Feature\PreDpc;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\Signature\Models\SignatureAttempt;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrescribesQualifications;
use Tests\TestCase;

/**
 * The eSign step before every submission and approval (2026-10-28): the officer
 * sees what they sign, consents, and signs — here through the simulator, which
 * is not a real eSign — and the name on the eSign decides.
 */
class EsignStepTest extends TestCase
{
    use PrescribesQualifications;
    use RefreshDatabase;

    private User $ao;

    private User $ah;

    private PreDpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $admin = User::factory()->assignedTo($department, Role::DepartmentAdmin)->create();
        $this->ao = User::factory()->assignedTo($department, Role::AuthorizedOfficer)->create(['name' => 'Ramesh Verma']);
        $this->ah = User::factory()->assignedTo($department, Role::AdministrativeHead)->create(['name' => 'Sunita Sharma']);
        $target = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Director', 'class_level' => 'I', 'status' => MasterStatus::Active->value]);
        $feeder = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Assistant Director', 'class_level' => 'I', 'status' => MasterStatus::Active->value]);

        $this->proposal = app(PreDpcProposalService::class)->createDraft($department, $admin, [
            'dpc_year' => 2026, 'promotion_to_post_id' => $target->id, 'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'promotion_sources' => [['promotion_from_post_id' => $feeder->id, 'promotion_percentage' => 100, 'min_experience_years' => 5, 'experience_unit' => 'years', 'qualification_ids' => $this->qualificationIds()]],
            'government_order_number' => 'GO/2026/SE/1', 'effective_from' => '2026-01-01',
        ]);
        app(ProposalAllotmentService::class)->allot($this->proposal, $this->ao, $this->ah, $admin);
    }

    private function esign(User $as, string $kind, string $name): void
    {
        $this->travel(2)->minutes(); // past the eSign rate limit between attempts
        $this->actingAs($as)->post(route('esign.start', ['kind' => $kind, 'uuid' => $this->proposal->uuid]), ['consent' => '1'])
            ->assertRedirect();
        $reference = (string) session('esign.pending')['reference'];
        $this->get(route('esign.simulator', ['reference' => $reference]))->assertOk()->assertSee('value="'.$as->name.'"', false);
        $this->post(route('esign.simulator.submit'), ['reference' => $reference, 'decision' => 'sign', 'name' => $name])
            ->assertRedirect(route('pre-dpc.show', $this->proposal));
    }

    public function test_the_ao_signs_through_the_esign_step_before_the_pre_dpc_goes_to_the_ah(): void
    {
        $this->actingAs($this->ao)->get(route('pre-dpc.show', $this->proposal))->assertOk()
            ->assertSee(route('esign.show', ['kind' => 'pre-dpc-submit', 'uuid' => $this->proposal->uuid]), false)
            ->assertSee(__('esign.button.submit'));
        $this->get(route('esign.show', ['kind' => 'pre-dpc-submit', 'uuid' => $this->proposal->uuid]))->assertOk()
            ->assertSee('Ramesh Verma')->assertSee($this->proposal->pre_dpc_number);

        // Consent first.
        $this->post(route('esign.start', ['kind' => 'pre-dpc-submit', 'uuid' => $this->proposal->uuid]), [])->assertSessionHasErrors('consent');

        $this->esign($this->ao, 'pre-dpc-submit', 'Shri Ramesh Verma');
        $this->assertSame(PreDpcStatus::Submitted, $this->proposal->fresh()->status);
        $this->assertSame('verified', SignatureAttempt::query()->sole()->outcome);
    }

    public function test_a_different_name_on_the_esign_stops_the_submission(): void
    {
        $this->esign($this->ao, 'pre-dpc-submit', 'R. K. Verma');

        $this->assertSame(PreDpcStatus::Draft, $this->proposal->fresh()->status);
        $this->assertSame('R. K. Verma', SignatureAttempt::query()->sole()->asserted_name);
        $this->get(route('pre-dpc.show', $this->proposal))->assertSee('id="esign-issue"', false);
    }

    public function test_a_cancelled_or_replayed_esign_does_nothing(): void
    {
        $this->actingAs($this->ao)->post(route('esign.start', ['kind' => 'pre-dpc-submit', 'uuid' => $this->proposal->uuid]), ['consent' => '1']);
        $reference = (string) session('esign.pending')['reference'];
        $this->post(route('esign.simulator.submit'), ['reference' => $reference, 'decision' => 'cancel'])->assertSessionHasErrors('workflow');
        $this->assertSame(PreDpcStatus::Draft, $this->proposal->fresh()->status);

        // The same eSign again: refused.
        $this->post(route('esign.simulator.submit'), ['reference' => $reference, 'decision' => 'sign', 'name' => 'Ramesh Verma'])->assertStatus(419);
        $this->assertSame(PreDpcStatus::Draft, $this->proposal->fresh()->status);
    }

    public function test_only_the_allotted_officer_reaches_the_esign_step(): void
    {
        $this->actingAs($this->ah)->get(route('esign.show', ['kind' => 'pre-dpc-submit', 'uuid' => $this->proposal->uuid]))->assertForbidden();
        $this->actingAs($this->ao)->get(route('esign.show', ['kind' => 'pre-dpc-approve', 'uuid' => $this->proposal->uuid]))->assertForbidden();
        $this->get(route('esign.show', ['kind' => 'something-else', 'uuid' => $this->proposal->uuid]))->assertNotFound();
    }

    public function test_the_ah_approves_through_the_same_esign_step_every_time(): void
    {
        $this->esign($this->ao, 'pre-dpc-submit', 'Ramesh Verma');

        $this->actingAs($this->ah)->get(route('pre-dpc.show', $this->proposal))->assertSee(__('esign.button.approve'));
        $this->esign($this->ah, 'pre-dpc-approve', 'S. Sharma');
        $this->assertSame(PreDpcStatus::Submitted, $this->proposal->fresh()->status, 'A mismatch does not approve.');

        $this->esign($this->ah, 'pre-dpc-approve', 'Sunita Sharma');
        $this->assertSame(PreDpcStatus::Approved, $this->proposal->fresh()->status);
        $this->assertSame(['verified', 'name_mismatch', 'verified'], SignatureAttempt::query()->orderBy('id')->pluck('outcome')->all());
    }
}
