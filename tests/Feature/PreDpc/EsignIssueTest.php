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
use App\Integrations\Contracts\SignatureProvider;
use Database\Seeders\DesignationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrescribesQualifications;
use Tests\Support\FakeSignatureProvider;
use Tests\TestCase;

/**
 * An eSign whose name does not match (2026-10-28): nothing moves on, the attempt
 * is recorded, the Pre-DPC and the Department Admin's list carry a notice, and the
 * Department Admin corrects the officer's details (or chooses another) so the
 * officer can sign again — for the AO's submission and the AH's approval alike.
 * The eSign provider is a fake.
 */
class EsignIssueTest extends TestCase
{
    use PrescribesQualifications;
    use RefreshDatabase;

    private Department $department;

    private User $admin;

    private User $ao;

    private User $ah;

    private FakeSignatureProvider $esign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DesignationSeeder::class]);

        $this->department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->admin = User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create();
        $this->ao = User::factory()->unverifiedIdentity()->assignedTo($this->department, Role::AuthorizedOfficer)->create(['name' => 'Ramesh Verma']);
        $this->ah = User::factory()->unverifiedIdentity()->assignedTo($this->department, Role::AdministrativeHead)->create(['name' => 'Sunita Sharma']);

        $this->esign = new FakeSignatureProvider;
        $this->app->instance(SignatureProvider::class, $this->esign);
    }

    private function draft(): PreDpcProposal
    {
        $target = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Director', 'class_level' => 'I', 'status' => MasterStatus::Active->value]);
        $feeder = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Assistant Director', 'class_level' => 'I', 'status' => MasterStatus::Active->value]);

        $proposal = app(PreDpcProposalService::class)->createDraft($this->department, $this->admin, [
            'dpc_year' => 2026, 'promotion_to_post_id' => $target->id, 'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'promotion_sources' => [['promotion_from_post_id' => $feeder->id, 'promotion_percentage' => 100, 'min_experience_years' => 5, 'experience_unit' => 'years', 'qualification_ids' => $this->qualificationIds()]],
            'government_order_number' => 'GO/2026/SE/1', 'effective_from' => '2026-01-01',
        ]);
        app(ProposalAllotmentService::class)->allot($proposal, $this->ao, $this->ah, $this->admin);

        return $proposal->refresh();
    }

    public function test_an_ao_mismatch_stops_the_pre_dpc_shows_a_notice_and_the_department_admin_corrects_it(): void
    {
        $proposal = $this->draft();
        $this->esign->mode = FakeSignatureProvider::OTHER_NAME;
        $this->esign->assertedName = 'Ramesh Kumar Verma';

        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal))->assertSessionHasErrors('workflow');
        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status, 'Not forwarded to the AH.');

        $attempt = SignatureAttempt::query()->sole();
        $this->assertSame(['submit', 'name_mismatch', 'Ramesh Kumar Verma', 'Ramesh Verma'], [$attempt->action, $attempt->outcome, $attempt->asserted_name, $attempt->portal_name]);

        // The notice, on the Pre-DPC and in the Department Admin's list.
        $this->get(route('pre-dpc.show', $proposal))->assertSee('id="esign-issue"', false)->assertSee('Ramesh Kumar Verma');
        $this->actingAs($this->admin)->get(route('department.allotments.index', ['view' => 'pre-dpc']))->assertSee('data-esign-issue', false);
        $this->get(route('department.allotments.pre-dpc.edit', $proposal))->assertSee('id="esign-issue"', false)->assertSee('id="details-ao"', false);

        // The Department Admin corrects the AO's name, with a reason.
        $this->put(route('department.officers.details', $this->ao), ['name' => 'Ramesh Kumar Verma', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->put(route('department.officers.details', $this->ao), ['name' => 'Ramesh Kumar Verma', 'gender' => 'M', 'reason' => 'As on the eSign certificate'])->assertSessionHasNoErrors();
        $this->assertSame('Ramesh Kumar Verma', $this->ao->fresh()->name);
        $this->assertDatabaseHas('identity_corrections', ['user_id' => $this->ao->id, 'stage' => 'esign_correction', 'field' => 'name', 'corrected_by' => $this->admin->id]);

        // The AO signs again: it now goes to the AH, and the notice is gone.
        $this->actingAs($this->ao->fresh())->post(route('pre-dpc.submit', $proposal))->assertSessionHasNoErrors();
        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
        $this->get(route('pre-dpc.show', $proposal))->assertDontSee('id="esign-issue"', false);
        $this->assertSame(['name_mismatch', 'verified'], SignatureAttempt::query()->orderBy('id')->pluck('outcome')->all());
    }

    public function test_an_ah_mismatch_stops_the_approval_with_the_same_notice(): void
    {
        $proposal = $this->draft();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);

        $this->esign->mode = FakeSignatureProvider::OTHER_NAME;
        $this->esign->assertedName = 'S. Sharma';
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal))->assertSessionHasErrors('workflow');
        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
        $this->actingAs($this->admin)->get(route('department.allotments.index', ['view' => 'pre-dpc']))->assertSee('data-esign-issue', false);

        // Another AH is chosen instead; the new AH signs.
        $other = User::factory()->assignedTo($this->department, Role::AdministrativeHead)->create();
        $this->esign->mode = FakeSignatureProvider::OK;
        app(ProposalAllotmentService::class)->allot($proposal->fresh(), $this->ao, $other, $this->admin, 'eSign name did not match');
        $this->actingAs($other->fresh())->post(route('pre-dpc.approve', $proposal))->assertSessionHasNoErrors();
        $this->assertSame(PreDpcStatus::Approved, $proposal->fresh()->status);
    }

    public function test_only_the_current_officers_of_a_running_proposal_can_be_corrected_and_only_by_their_department(): void
    {
        $proposal = $this->draft();
        $stranger = User::factory()->create();
        $this->actingAs($this->admin)->put(route('department.officers.details', $stranger), ['name' => 'X', 'reason' => 'x'])->assertForbidden();

        $elsewhere = Department::query()->create(['code' => 'OTH', 'name' => 'Other', 'status' => 'registered']);
        $foreignAdmin = User::factory()->assignedTo($elsewhere, Role::DepartmentAdmin)->create();
        $this->actingAs($foreignAdmin)->put(route('department.officers.details', $this->ao), ['name' => 'X', 'reason' => 'x'])->assertForbidden();

        // A DigiLocker-verified identity stays locked.
        $this->ao->forceFill(['identity_verified_at' => now()])->save();
        $this->actingAs($this->admin)->put(route('department.officers.details', $this->ao), ['name' => 'X', 'reason' => 'x'])->assertSessionHasErrors('officer_details');
        $this->assertSame('Ramesh Verma', $this->ao->fresh()->name);
        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);
    }
}
