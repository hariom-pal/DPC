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
use Illuminate\Support\Facades\DB;
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
        $this->actingAs($as)->post(route('esign.start', ['kind' => $kind, 'uuid' => $this->proposal->uuid]), ['consent' => '1', 'declaration' => '1'])
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

        // And the declaration, shown in Hindi and English (MoM of 2026-10-29).
        $this->get(route('esign.show', ['kind' => 'pre-dpc-submit', 'uuid' => $this->proposal->uuid]))
            ->assertSee(config('dpc.declarations.pre_dpc_submit.hi'))->assertSee(config('dpc.declarations.pre_dpc_submit.en'));
        $this->post(route('esign.start', ['kind' => 'pre-dpc-submit', 'uuid' => $this->proposal->uuid]), ['consent' => '1'])
            ->assertSessionHasErrors('declaration');

        $this->esign($this->ao, 'pre-dpc-submit', 'Shri Ramesh Verma');
        $this->assertSame(PreDpcStatus::Submitted, $this->proposal->fresh()->status);
        $this->assertSame('verified', SignatureAttempt::query()->sole()->outcome);
    }

    /**
     * An incomplete Pre-DPC is refused before the eSign, not after it (2026-11-04):
     * the page lists what is missing with Submit disabled, and the eSign step sends
     * the AO back without anything signed.
     */
    public function test_an_incomplete_pre_dpc_is_refused_before_the_esign_starts(): void
    {
        $this->proposal->forceFill(['government_order_number' => null])->save();
        $missing = 'The Adyatan Vibhagiy Bharti Niyam and its Publish Date are required before submitting.';

        $this->actingAs($this->ao)->get(route('pre-dpc.show', $this->proposal))->assertOk()
            ->assertSee('id="pre-dpc-blockers"', false)
            ->assertSee($missing)
            ->assertSee('data-esign disabled', false);

        $this->get(route('esign.show', ['kind' => 'pre-dpc-submit', 'uuid' => $this->proposal->uuid]))
            ->assertRedirect(route('pre-dpc.show', $this->proposal))
            ->assertSessionHasErrors(['workflow' => $missing]);
        $this->post(route('esign.start', ['kind' => 'pre-dpc-submit', 'uuid' => $this->proposal->uuid]), ['consent' => '1', 'declaration' => '1'])
            ->assertRedirect(route('pre-dpc.show', $this->proposal))
            ->assertSessionHasErrors(['workflow' => $missing]);

        $this->assertNull(session('esign.pending'));
        $this->assertSame(0, SignatureAttempt::query()->count());
        $this->assertSame(PreDpcStatus::Draft, $this->proposal->fresh()->status);
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
        $this->actingAs($this->ao)->post(route('esign.start', ['kind' => 'pre-dpc-submit', 'uuid' => $this->proposal->uuid]), ['consent' => '1', 'declaration' => '1']);
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

    /**
     * The AH signs a return for correction too (2026-11-04): the remarks go to the
     * eSign step and are signed with the return; a mismatched name leaves it submitted.
     */
    public function test_the_ah_returns_for_correction_through_the_esign_step(): void
    {
        $this->esign($this->ao, 'pre-dpc-submit', 'Ramesh Verma');
        $url = route('esign.show', ['kind' => 'pre-dpc-return', 'uuid' => $this->proposal->uuid]);

        $this->actingAs($this->ah)->get(route('pre-dpc.show', $this->proposal))->assertOk()
            ->assertSee('id="pre-dpc-return"', false)->assertSee($url, false);
        $this->get($url.'?return_remarks=Correct+the+GO')->assertOk()
            ->assertSee(__('esign.page.title_pre-dpc-return'))->assertSee('Correct the GO');
        $this->actingAs($this->ao)->get($url)->assertForbidden();

        // Remarks are required, as before.
        $this->travel(2)->minutes();
        $this->actingAs($this->ah)->post(route('esign.start', ['kind' => 'pre-dpc-return', 'uuid' => $this->proposal->uuid]), ['consent' => '1'])
            ->assertSessionHasErrors('return_remarks');

        $sign = function (string $name): void {
            $this->travel(2)->minutes();
            $this->actingAs($this->ah)->post(route('esign.start', ['kind' => 'pre-dpc-return', 'uuid' => $this->proposal->uuid]), ['consent' => '1', 'return_remarks' => 'Correct the GO number.'])
                ->assertRedirect();
            $reference = (string) session('esign.pending')['reference'];
            $this->post(route('esign.simulator.submit'), ['reference' => $reference, 'decision' => 'sign', 'name' => $name])
                ->assertRedirect(route('pre-dpc.show', $this->proposal));
        };

        $sign('S. Sharma');
        $this->assertSame(PreDpcStatus::Submitted, $this->proposal->fresh()->status);

        $sign('Sunita Sharma');
        $fresh = $this->proposal->fresh();
        $this->assertSame(PreDpcStatus::Returned, $fresh->status);
        $this->assertSame('Correct the GO number.', $fresh->return_remarks);
        $transition = DB::table('workflow_transitions')->where('event', 'return')->sole();
        $this->assertNotNull($transition->signature_id, 'The return carries the AH\'s signature.');
        $this->assertSame(['verified', 'name_mismatch', 'verified'], SignatureAttempt::query()->orderBy('id')->pluck('outcome')->all());
    }
}
