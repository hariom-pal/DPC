<?php

declare(strict_types=1);

namespace Tests\Feature\Allotment;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrescribesQualifications;
use Tests\TestCase;

/**
 * The Department Admin's DPC screens (2026-10-28, reworked 2026-10-29): the AO /
 * AH picker suggests the department's own officers and released officers, finds
 * one by e-mail, or adds one with both OTPs in the Department Admin's department;
 * the DPCs are listed as running or signed; User Management views the officers
 * and releases a transferred one.
 */
class DepartmentOfficerPickerTest extends TestCase
{
    use PrescribesQualifications;
    use RefreshDatabase;

    private Department $department;

    private Department $other;

    private User $admin;

    private DepartmentPost $post;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, LookupMasterSeeder::class]);

        $this->department = Department::query()->create(['code' => 'PHE', 'name' => 'Public Health Engineering', 'status' => 'registered']);
        $this->other = Department::query()->create(['code' => 'HED', 'name' => 'Higher Education', 'status' => 'registered']);
        $this->admin = User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create();
        $this->post = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Executive Engineer', 'class_level' => 'I', 'status' => MasterStatus::Active->value]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newOfficer(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Shri New Officer', 'email' => 'new.officer@cg.gov.in', 'mobile' => '9123456780',
            'gender' => 'M', 'date_of_birth' => '1980-05-01',
            'role' => Role::AuthorizedOfficer->value, 'email_code' => '123456', 'mobile_code' => '123456',
        ];
    }

    public function test_own_and_released_officers_are_found_but_another_departments_are_not(): void
    {
        $own = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create(['email' => 'phe.officer@cg.gov.in', 'name' => 'PHE Officer']);
        User::factory()->assignedTo($this->other, Role::AuthorizedOfficer)->create(['email' => 'he.officer@cg.gov.in', 'name' => 'HE Officer']);
        $released = User::factory()->create(['email' => 'free.officer@cg.gov.in', 'name' => 'Released Officer', 'released_at' => now(), 'released_from_department_id' => $this->other->id]);

        $this->actingAs($this->admin)->getJson(route('department.officers.lookup', ['email' => 'PHE.Officer@cg.gov.in']))
            ->assertOk()->assertJson(['found' => true, 'id' => $own->id, 'name' => 'PHE Officer', 'department' => 'Public Health Engineering', 'released' => false]);
        $this->getJson(route('department.officers.lookup', ['email' => 'free.officer@cg.gov.in']))
            ->assertOk()->assertJson(['found' => true, 'id' => $released->id, 'released' => true, 'released_from' => 'Higher Education']);
        $this->getJson(route('department.officers.lookup', ['email' => 'he.officer@cg.gov.in']))
            ->assertOk()->assertJson(['found' => false, 'blocked' => true, 'message' => __('allotments.picker.other_department', ['department' => 'Higher Education'])]);
        $this->getJson(route('department.officers.lookup', ['email' => 'nobody@cg.gov.in']))->assertOk()->assertJson(['found' => false]);

        $member = User::factory()->create(['email' => 'member@cgpsc.gov.in']);
        $member->assignRole(Role::Member->value);
        $this->getJson(route('department.officers.lookup', ['email' => 'member@cgpsc.gov.in']))->assertOk()->assertJson(['found' => false, 'blocked' => true]);

        // Suggestions as the e-mail is typed: own and released only, never the Department Admin.
        $emails = collect($this->getJson(route('department.officers.search', ['q' => 'officer']))->assertOk()->json('officers'))->pluck('email')->all();
        $this->assertEqualsCanonicalizing(['phe.officer@cg.gov.in', 'free.officer@cg.gov.in'], $emails);
        $this->assertSame([], $this->getJson(route('department.officers.search', ['q' => $this->admin->email]))->json('officers'));
    }

    public function test_a_chosen_officers_mobile_changes_only_with_an_otp_to_the_new_number(): void
    {
        $own = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create(['mobile' => '9000000001']);
        $foreign = User::factory()->assignedTo($this->other, Role::AuthorizedOfficer)->create();

        $this->actingAs($this->admin)->postJson(route('department.officers.mobile.otp', $own), ['mobile' => '9000000002'])->assertOk();
        $this->putJson(route('department.officers.mobile', $own), ['mobile' => '9000000002', 'mobile_code' => '000000'])->assertStatus(422);
        $this->assertSame('9000000001', $own->fresh()?->mobile);

        $this->putJson(route('department.officers.mobile', $own), ['mobile' => '9000000002', 'mobile_code' => '123456'])
            ->assertOk()->assertJson(['mobile' => '9000000002']);
        $this->assertNotNull($own->fresh()?->mobile_verified_at);

        $this->postJson(route('department.officers.mobile.otp', $foreign), ['mobile' => '9000000003'])->assertForbidden();
    }

    public function test_a_new_officer_is_added_only_with_both_otps(): void
    {
        $this->actingAs($this->admin)->postJson(route('department.officers.otp'), ['email' => 'new.officer@cg.gov.in', 'mobile' => '9123456780'])
            ->assertOk()->assertJson(['sent' => true]);

        $this->postJson(route('department.officers.store'), $this->newOfficer(['mobile_code' => '000000']))->assertStatus(422);
        $this->assertDoesNotMatchRegularExpression('/picker-department/', (string) file_get_contents(resource_path('views/department/allotments/_officers.blade.php')));
        $this->assertFalse(User::query()->where('email', 'new.officer@cg.gov.in')->exists());

        $response = $this->postJson(route('department.officers.store'), $this->newOfficer(['department_id' => $this->other->id]))->assertOk()->assertHeader('Cache-Control');
        $officer = User::query()->where('email', 'new.officer@cg.gov.in')->sole();
        $this->assertSame($officer->id, $response->json('id'));
        $this->assertNotEmpty($response->json('password'));
        $this->assertSame(['1980-05-01', 'M'], [$officer->date_of_birth?->toDateString(), $officer->gender]);
        $this->assertNotNull($officer->email_verified_at);
        $this->assertNotNull($officer->mobile_verified_at);
        $this->assertTrue($officer->must_change_password);
        // Always in the Department Admin's own department (2026-10-29); a department sent by the browser is ignored.
        $this->assertTrue(UserDepartmentAssignment::query()->current()->where('user_id', $officer->id)->where('department_id', $this->department->id)->where('role', Role::AuthorizedOfficer->value)->exists());

        // An e-mail that already has an account is chosen, never added twice.
        $this->travel(2)->minutes(); // past the OTP rate limit
        $this->postJson(route('department.officers.otp'), ['email' => 'new.officer@cg.gov.in', 'mobile' => '9123456789'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_messages_say_what_is_wrong(): void
    {
        $this->actingAs($this->admin)->postJson(route('department.officers.otp'), ['email' => 'someone@gmail.com', 'mobile' => '9123456780'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', __('department.users.validation.gov_email'));

        $this->post(route('department.allotments.pre-dpc.store'), ['dpc_year' => 2026, 'promotion_to_post_id' => $this->post->id]);
        $this->put(route('department.allotments.pre-dpc.update', PreDpcProposal::query()->sole()), [])
            ->assertSessionHasErrors(['ao_user_id' => __('allotments.picker.choose_ao'), 'ah_user_id' => __('allotments.picker.choose_ah')]);
    }

    public function test_a_complete_pre_dpc_is_allotted_to_own_or_released_officers_only(): void
    {
        $ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();
        $released = User::factory()->create(['released_at' => now(), 'released_from_department_id' => $this->other->id]);
        $foreign = User::factory()->assignedTo($this->other, Role::AdministrativeHead)->create();
        $feeder = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Assistant Engineer', 'class_level' => 'II', 'status' => MasterStatus::Active->value]);
        $proposal = app(PreDpcProposalService::class)->createDraft($this->department, $this->admin, [
            'dpc_year' => 2026, 'promotion_to_post_id' => $this->post->id, 'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'promotion_sources' => [['promotion_from_post_id' => $feeder->id, 'promotion_percentage' => 100, 'min_experience_years' => 5, 'experience_unit' => 'years', 'qualification_ids' => $this->qualificationIds()]],
            'government_order_number' => 'GO/2026/PHE/1', 'effective_from' => '2026-01-01',
        ]);

        $this->actingAs($this->admin)->get(route('department.allotments.pre-dpc.edit', $proposal))->assertOk()
            ->assertSee('officer-picker', false)->assertSee('js/forms/officer-picker.js', false)
            ->assertSee(__('allotments.picker.hint'));

        // Another department's officer, not released: refused.
        $this->put(route('department.allotments.pre-dpc.update', $proposal), ['ao_user_id' => $ao->id, 'ah_user_id' => $foreign->id])
            ->assertSessionHasErrors(['ao_user_id' => __('allotments.errors.not_in_pool', ['name' => $foreign->name])]);

        // A released officer: allotted, and posted to this department.
        $this->put(route('department.allotments.pre-dpc.update', $proposal), ['ao_user_id' => $ao->id, 'ah_user_id' => $released->id])->assertSessionHasNoErrors();

        $allotments = app(ProposalAllotmentService::class);
        $this->assertTrue($allotments->isAllotted($ao->fresh(), $proposal, Role::AuthorizedOfficer));
        $this->assertTrue($allotments->isAllotted($released->fresh(), $proposal, Role::AdministrativeHead));
        $this->assertNull($released->fresh()?->released_at);
        $this->assertSame($this->department->id, $released->fresh()?->department_id);
    }

    public function test_pre_dpcs_running_dpcs_and_closed_dpcs_are_listed_apart(): void
    {
        $preDpc = PreDpcProposal::query()->forceCreate([
            'pre_dpc_number' => 'PDPC-2026-PHE-0001', 'department_id' => $this->department->id, 'dpc_year' => 2026,
            'promotion_to_post_id' => $this->post->id, 'version' => 1, 'status' => 'draft',
        ]);
        $raised = PreDpcProposal::query()->forceCreate([
            'pre_dpc_number' => 'PDPC-2025-PHE-0002', 'department_id' => $this->department->id, 'dpc_year' => 2025,
            'promotion_to_post_id' => $this->post->id, 'version' => 1, 'status' => 'approved', 'approved_at' => now(),
        ]);
        $type = DpcType::query()->where('code', 'fresh')->value('id');
        $running = DpcProposal::query()->forceCreate([
            'department_id' => $this->department->id, 'pre_dpc_proposal_id' => $raised->id, 'dpc_code' => 'DPC-2025-PHE-0001', 'dpc_type_id' => $type, 'promotion_to_post_id' => $this->post->id, 'promotion_from_post_id' => $this->post->id,
            'status' => 'submitted', 'proposal_date' => now()->toDateString(),
        ]);
        $closed = DpcProposal::query()->forceCreate([
            'department_id' => $this->department->id, 'pre_dpc_proposal_id' => $raised->id, 'dpc_code' => 'DPC-2025-PHE-0002', 'dpc_type_id' => $type, 'promotion_to_post_id' => $this->post->id, 'promotion_from_post_id' => $this->post->id,
            'status' => 'cancelled', 'proposal_date' => now()->toDateString(),
        ]);
        [$running, $closed] = [$running->fresh(), $closed->fresh()];

        $this->actingAs($this->admin)->get(route('department.allotments.index', ['view' => 'pre-dpc']))->assertOk()
            ->assertSee('PDPC-2026-PHE-0001')->assertDontSee('PDPC-2025-PHE-0002', false);
        $this->get(route('department.allotments.index'))->assertOk()
            ->assertSee($running->citation())->assertDontSee($closed->citation())->assertDontSee('PDPC-2026-PHE-0001');
        $this->get(route('department.allotments.index', ['view' => 'closed']))->assertOk()
            ->assertSee($closed->citation())->assertDontSee($running->citation());
    }

    public function test_a_draft_pre_dpc_can_be_filled_in_from_the_list_even_before_allotment(): void
    {
        $this->actingAs($this->admin)->post(route('department.allotments.pre-dpc.store'), ['dpc_year' => 2026, 'promotion_to_post_id' => $this->post->id]);
        $draft = PreDpcProposal::query()->sole();

        $this->get(route('department.allotments.index', ['view' => 'pre-dpc']))->assertOk()
            ->assertSee(route('pre-dpc.edit', $draft), false)->assertSee(__('allotments.edit_pre_dpc'));
        $this->get(route('pre-dpc.edit', $draft))->assertOk();
        $this->get(route('department.allotments.pre-dpc.edit', $draft))->assertOk()->assertSee(route('pre-dpc.edit', $draft), false);
    }

    public function test_user_management_lists_the_department_and_releases_a_transferred_officer(): void
    {
        $officer = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create(['name' => 'Transferred Officer']);
        $ah = User::factory()->assignedTo($this->department, Role::AdministrativeHead)->create();
        $outsider = User::factory()->assignedTo($this->other, Role::AuthorizedOfficer)->create(['name' => 'Outsider Officer']);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee(route('department.users.index'), false)
            ->assertDontSee('/department/assignments', false);
        $this->get('/department/assignments')->assertNotFound();
        $this->get(route('department.users.index'))->assertOk()
            ->assertSee('Transferred Officer')->assertDontSee('Outsider Officer');

        // Still the AO of a Pre-DPC in progress: not released until another is allotted.
        $this->post(route('department.allotments.pre-dpc.store'), ['dpc_year' => 2026, 'promotion_to_post_id' => $this->post->id]);
        $proposal = PreDpcProposal::query()->sole();
        app(ProposalAllotmentService::class)->allot($proposal, $officer, $ah, $this->admin);
        $this->post(route('department.users.release', $officer), ['reason' => 'Transfer order 12/2026'])
            ->assertSessionHasErrors(['release' => __('department.users.release.open_work', ['name' => $officer->name])]);

        $replacement = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();
        app(ProposalAllotmentService::class)->allot($proposal, $replacement, $ah, $this->admin, 'Officer transferred');

        $this->post(route('department.users.release', $officer), [])->assertSessionHasErrors('reason');
        $this->post(route('department.users.release', $officer), ['reason' => 'Transfer order 12/2026'])->assertSessionHasNoErrors();

        $officer = $officer->fresh();
        $this->assertNotNull($officer?->released_at);
        $this->assertSame($this->department->id, $officer?->released_from_department_id);
        $this->assertNull($officer?->department_id);
        $this->assertTrue($officer?->is_active);

        // Another department's officer cannot be released from here.
        $this->post(route('department.users.release', $outsider), ['reason' => 'x'])
            ->assertSessionHasErrors(['release' => __('department.users.release.not_member', ['name' => $outsider->name])]);

        // The released officer is now offered to the other department.
        $otherAdmin = User::factory()->assignedTo($this->other, Role::DepartmentAdmin)->create();
        $this->actingAs($otherAdmin)->getJson(route('department.officers.lookup', ['email' => $officer?->email]))
            ->assertOk()->assertJson(['found' => true, 'released' => true, 'released_from' => 'Public Health Engineering']);
    }

    public function test_only_the_department_admin_uses_the_picker(): void
    {
        $officer = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();

        $this->actingAs($officer)->getJson(route('department.officers.lookup', ['email' => 'x@cg.gov.in']))->assertForbidden();
        $this->postJson(route('department.officers.store'), $this->newOfficer())->assertForbidden();
    }
}
