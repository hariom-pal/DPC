<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AO's and AH's profile at every sign-in (2026-10-28): details read only, a
 * declaration that they are correct, and the department — which they may change
 * at once, keeping the DPCs they are allotted to.
 */
class OfficerProfileTest extends TestCase
{
    use RefreshDatabase;

    private Department $alpha;

    private Department $beta;

    private User $ao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->alpha = Department::query()->create(['code' => 'ALPHA', 'name' => 'Alpha Department', 'status' => 'registered']);
        $this->beta = Department::query()->create(['code' => 'BETA', 'name' => 'Beta Department', 'status' => 'registered']);
        $this->ao = User::factory()->assignedTo($this->alpha, Role::AuthorizedOfficer)->create(['must_change_password' => false, 'name' => 'Alpha Officer']);
    }

    private function signIn(User $user): void
    {
        $this->travel(2)->minutes(); // past the sign-in rate limits between sign-ins
        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password'])->assertRedirect(route('verify.show'));
        $this->passSignInChallenge()->assertRedirect(route('dashboard'));
    }

    public function test_the_profile_comes_first_at_every_sign_in_and_needs_the_declaration(): void
    {
        $this->signIn($this->ao);
        $this->get(route('dashboard'))->assertRedirect(route('profile.show'));
        $this->get(route('profile.show'))->assertOk()->assertSee('Alpha Officer')->assertSee(__('profile.disclaimer'))
            ->assertDontSee('name="name"', false)->assertDontSee('name="mobile"', false);

        $this->post(route('profile.confirm'), [])->assertSessionHasErrors('confirm');
        $this->post(route('profile.confirm'), ['confirm' => '1'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();

        // The next sign-in asks again.
        $this->post(route('logout'));
        $this->signIn($this->ao);
        $this->get(route('dashboard'))->assertRedirect(route('profile.show'));
    }

    /**
     * The department is read only on the profile (2026-10-29): a transfer is the
     * Department Admin's release and the new department's choice.
     */
    public function test_the_department_is_shown_but_cannot_be_changed_here(): void
    {
        $this->signIn($this->ao);
        $this->get(route('profile.show'))->assertOk()->assertSee($this->alpha->name)
            ->assertDontSee('name="department_id"', false)->assertSee(__('profile.department_help'));

        $this->post(route('profile.confirm'), ['department_id' => $this->beta->id, 'confirm' => '1'])->assertRedirect(route('dashboard'));
        $this->assertSame($this->alpha->id, $this->ao->fresh()->department_id);
        $this->assertDatabaseMissing('security_events', ['user_id' => $this->ao->id, 'event' => 'profile.department_changed']);
    }

    /**
     * A first sign-in with a system-issued password: the password first, then
     * the profile — never a loop between the two (2026-10-28).
     */
    public function test_a_pending_password_change_comes_before_the_profile_without_a_loop(): void
    {
        $this->ao->forceFill(['must_change_password' => true])->save();
        $this->signIn($this->ao);

        $this->get(route('dashboard'))->assertRedirect(route('password.change.show'));
        $this->get(route('password.change.show'))->assertOk();
        $this->post(route('password.change.update'), [
            'current_password' => 'password', 'password' => 'Officer@2026', 'password_confirmation' => 'Officer@2026',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertRedirect(route('profile.show'));
        $this->get(route('profile.show'))->assertOk();
        $this->post(route('profile.confirm'), ['department_id' => $this->alpha->id, 'confirm' => '1'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_only_an_ao_or_ah_has_this_profile(): void
    {
        $admin = User::factory()->assignedTo($this->alpha, Role::DepartmentAdmin)->create();

        $this->actingAs($admin)->get(route('profile.show'))->assertForbidden();
        $this->signIn($admin);
        $this->get(route('dashboard'))->assertOk();
    }
}
