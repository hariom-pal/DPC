<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\PreDpcLinkReconciler;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Suggestion 3: the sanctioning order of a DPC raised before change item 11 is
 * recorded by a person, never derived.
 */
class PreDpcLinkReconcilerTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $admin;

    private User $ao;

    private Department $department;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);

        $this->proposal = $this->raiseDpc(
            $this->ao,
            $this->departmentPost('Under Secretary', 5),
            $this->departmentPost('Deputy Secretary'),
        );
    }

    private function departmentPost(string $name, ?int $duration = null): DepartmentPost
    {
        return DepartmentPost::query()->create([
            'department_id' => $this->department->id,
            'name' => $name,
            'class_level' => 'I',
            'promotion_duration_years' => $duration,
            'status' => MasterStatus::Active->value,
        ]);
    }

    private function reconciler(): PreDpcLinkReconciler
    {
        return app(PreDpcLinkReconciler::class);
    }

    /**
     * A proposal from before item 11: linked by `raiseDpc()`, then unlinked to
     * stand for what migration 360007 left behind.
     */
    private function legacy(): DpcProposal
    {
        $this->proposal->forceFill(['pre_dpc_proposal_id' => null])->save();

        return $this->proposal->fresh() ?? $this->proposal;
    }

    public function test_a_linked_proposal_is_not_offered(): void
    {
        $this->assertTrue($this->reconciler()->unlinked()->isEmpty());
    }

    public function test_an_unlinked_proposal_is_listed_with_its_candidate_order(): void
    {
        $proposal = $this->legacy();

        $this->assertSame([$proposal->id], $this->reconciler()->unlinked()->pluck('id')->all());
        $this->assertSame(1, $this->reconciler()->candidatesFor($proposal)->count());
    }

    public function test_an_order_for_another_post_pair_is_not_a_candidate(): void
    {
        $proposal = $this->legacy();

        // Same department, different pair — an order that could not have sanctioned it.
        $other = PreDpcProposal::query()->create([
            'department_id' => $this->department->id,
            'promotion_to_post_id' => $this->departmentPost('Joint Secretary')->id,
            'dpc_year' => $proposal->dpc_year,
            'status' => 'approved',
            'version' => 1,
            'direct_recruitment_pct' => 0,
            'promotion_recruitment_pct' => 100,
        ]);

        $other->promotionSources()->create([
            'promotion_from_post_id' => $this->departmentPost('Section Officer', 4)->id,
            'promotion_percentage' => 100,
            'min_experience_years' => 5,
            'experience_unit' => 'years',
        ]);

        $this->assertSame(1, $this->reconciler()->candidatesFor($proposal)->count());
    }

    public function test_the_administrator_records_the_order_and_it_is_attributed(): void
    {
        $proposal = $this->legacy();
        $order = $this->reconciler()->candidatesFor($proposal)->sole();

        $this->actingAs($this->admin)->get(route('admin.pre-dpc-links.index'))
            ->assertOk()
            ->assertSee($proposal->dpc_code);

        $this->actingAs($this->admin)
            ->post(route('admin.pre-dpc-links.store', $proposal), ['pre_dpc_proposal_id' => $order->id])
            ->assertRedirect(route('admin.pre-dpc-links.index'));

        $this->assertSame($order->id, $proposal->fresh()?->pre_dpc_proposal_id);

        // The only evidence is who said so, so that is what is recorded.
        $this->assertDatabaseHas('activity_timelines', [
            'event' => 'dpc_proposal.pre_dpc_recorded',
            'subject_id' => $proposal->id,
        ]);
    }

    public function test_a_recorded_link_is_never_overwritten(): void
    {
        $order = $this->reconciler()->candidatesFor($this->legacy())->sole();
        $this->reconciler()->link($this->proposal->fresh(), $order, 'The Administrator');

        $this->expectException(RuntimeException::class);
        $this->reconciler()->link($this->proposal->fresh(), $order, 'Somebody Else');
    }

    public function test_an_order_from_another_department_is_refused(): void
    {
        $proposal = $this->legacy();

        $forest = Department::query()->create(['code' => 'FOR', 'name' => 'Forest', 'status' => 'active']);
        $foreign = PreDpcProposal::query()->create([
            'department_id' => $forest->id,
            'promotion_to_post_id' => $proposal->promotion_to_post_id,
            'dpc_year' => $proposal->dpc_year,
            'status' => 'approved',
            'version' => 1,
            'direct_recruitment_pct' => 0,
            'promotion_recruitment_pct' => 100,
        ]);

        // Named so the order is refused for its *department* and not for failing to
        // sanction the feeder — one reason at a time, or the test proves neither.
        $foreign->promotionSources()->create([
            'promotion_from_post_id' => $proposal->promotion_from_post_id,
            'promotion_percentage' => 100,
            'min_experience_years' => 5,
            'experience_unit' => 'years',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.pre-dpc-links.store', $proposal), ['pre_dpc_proposal_id' => $foreign->id])
            ->assertSessionHasErrors('pre_dpc_proposal_id');

        $this->assertNull($proposal->fresh()?->pre_dpc_proposal_id);
    }

    public function test_only_the_administrator_reaches_the_screen(): void
    {
        $this->actingAs($this->ao)->get(route('admin.pre-dpc-links.index'))->assertForbidden();
    }
}
