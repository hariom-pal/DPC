<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\ActiveRoleService;
use App\Domain\Department\Models\Department;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One account, several roles, and the one it is **acting as** (change register of
 * 2026-08-26, item 9 as clarified).
 *
 * > *A member is also a user — it can work as AO or AH too. Members will have their
 * > own dashboard as per the role assigned, so a member can switch their roles.*
 *
 * **The property these tests defend is the gating, not the menu.** A switcher that
 * only changed which dashboard was shown would leave the account able to act in
 * every capacity it holds from a direct link, and the portal could not say which
 * capacity an approval was given in. So the assertions that matter are the ones
 * checking that a capacity the account holds is **refused** while it is acting as
 * another.
 */
class RoleSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    /** Holds a departmental role *and* a Commission role at once. */
    private User $both;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education', 'status' => 'active',
        ]);

        $this->both = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create([
            'name' => 'Shri R. Sahu',
        ]);
        $this->both->assignRole(Role::Member->value);
    }

    // ------------------------------------------------------------- resolution

    public function test_an_account_with_one_role_is_always_acting_as_it(): void
    {
        $single = User::factory()->create();
        $single->assignRole(Role::Admin->value);

        $this->actingAs($single)->get(route('dashboard'))->assertOk();

        $this->assertSame([Role::Admin], $single->grantedRoles());
    }

    public function test_an_account_with_several_roles_may_list_them_all(): void
    {
        $granted = $this->both->grantedRoles();

        $this->assertContains(Role::AuthorizedOfficer, $granted);
        $this->assertContains(Role::Member, $granted);
    }

    /**
     * The switcher has to keep working after the middleware has narrowed the loaded
     * relation — otherwise it offers only the role already active, which is a
     * switcher that cannot switch.
     */
    public function test_the_switcher_is_offered_on_screen_with_every_held_role(): void
    {
        $this->actingAs($this->both)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Authorized Officer')
            ->assertSee('Member, CGPSC');
    }

    public function test_an_account_with_one_role_is_offered_no_switcher(): void
    {
        $single = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->actingAs($single)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Acting as:');
    }

    // ---------------------------------------------------------------- gating

    /**
     * The assertion the whole feature exists for.
     */
    public function test_the_active_role_gates_the_session(): void
    {
        // Acting as the Authorized Officer — the precedence default — the
        // department's screens open and the Commission's do not.
        $this->actingAs($this->both)->get(route('pre-dpc.index'))->assertOk();
        $this->actingAs($this->both)->get(route('cgpsc.allotments.index'))->assertForbidden();

        $this->actingAs($this->both)->post(route('acting-as'), ['role' => Role::Member->value])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        // Having switched, exactly the other way round — and the departmental
        // screen is now refused even though the account still holds the role.
        $this->actingAs($this->both)->get(route('cgpsc.allotments.index'))->assertOk();
        $this->actingAs($this->both)->get(route('pre-dpc.index'))->assertForbidden();
    }

    public function test_switching_back_restores_the_first_capacity(): void
    {
        $this->actingAs($this->both)->post(route('acting-as'), ['role' => Role::Member->value]);
        $this->actingAs($this->both)->get(route('pre-dpc.index'))->assertForbidden();

        $this->actingAs($this->both)->post(route('acting-as'), ['role' => Role::AuthorizedOfficer->value]);
        $this->actingAs($this->both)->get(route('pre-dpc.index'))->assertOk();
    }

    public function test_an_account_cannot_act_as_a_role_it_does_not_hold(): void
    {
        $this->actingAs($this->both)->post(route('acting-as'), ['role' => Role::Admin->value])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('role');

        // And the refusal is not merely cosmetic.
        $this->actingAs($this->both)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $this->actingAs($this->both)->post(route('acting-as'), ['role' => 'emperor'])
            ->assertSessionHasErrors('role');
    }

    /**
     * A stale session — an Administrator withdrew the grant while the user was
     * signed in — must fall back to a role the account still holds, never to the
     * union of everything it once had.
     */
    public function test_a_session_naming_a_withdrawn_role_falls_back(): void
    {
        $this->actingAs($this->both)->post(route('acting-as'), ['role' => Role::Member->value]);

        $this->both->removeRole(Role::Member->value);

        $this->actingAs($this->both)->get(route('cgpsc.allotments.index'))->assertForbidden();
        $this->actingAs($this->both)->get(route('pre-dpc.index'))->assertOk();
    }

    public function test_switching_is_recorded_on_the_timeline(): void
    {
        $this->actingAs($this->both)->post(route('acting-as'), ['role' => Role::Member->value]);

        $this->assertDatabaseHas('activity_timelines', ['event' => 'user.role_switched']);
    }

    /**
     * Switching to the capacity already active is a no-op, and must not fill the
     * timeline with entries recording that nothing happened.
     */
    public function test_switching_to_the_current_role_records_nothing(): void
    {
        $this->actingAs($this->both)->post(route('acting-as'), ['role' => Role::AuthorizedOfficer->value]);
        $this->actingAs($this->both)->post(route('acting-as'), ['role' => Role::AuthorizedOfficer->value]);

        $this->assertSame(0, \DB::table('activity_timelines')->where('event', 'user.role_switched')->count());
    }

    public function test_switching_requires_a_post(): void
    {
        // As a link it would be reachable by anything that follows links, and an
        // officer could be moved into another capacity without acting.
        $this->actingAs($this->both)->get('/acting-as')->assertStatus(405);
    }

    public function test_a_guest_cannot_switch(): void
    {
        $this->post(route('acting-as'), ['role' => Role::Member->value])->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------- the service

    public function test_the_service_refuses_a_role_the_account_does_not_hold(): void
    {
        $this->expectException(\RuntimeException::class);

        app(ActiveRoleService::class)->switchTo($this->both, Role::Admin);
    }
}
