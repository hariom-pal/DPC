<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Authentication\Models\User;
use App\Domain\UserManagement\IssuedCredentials;
use App\Domain\UserManagement\Services\UserProvisioningService;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * `php artisan dpc:bootstrap-super-admin` (business decision, 2026-10-26).
 */
class BootstrapSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function bootstrap(array $options = []): array
    {
        $code = Artisan::call('dpc:bootstrap-super-admin', array_replace([
            '--name' => 'Portal Super Admin', '--email' => 'superadmin@cgpsc.gov.in', '--mobile' => '9000000701',
        ], $options));

        return [$code, Artisan::output()];
    }

    private function printedPassword(string $output): string
    {
        preg_match('/Temporary password \(shown once, not stored\): (\S+)/', $output, $match);
        $this->assertNotEmpty($match, $output);

        return $match[1];
    }

    public function test_it_creates_the_super_admin_with_a_random_password_shown_once(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$code, $output] = $this->bootstrap();
        $this->assertSame(0, $code, $output);
        $password = $this->printedPassword($output);

        $admin = User::query()->where('email', 'superadmin@cgpsc.gov.in')->sole();
        $this->assertTrue($admin->hasRole(Role::Admin->value));
        $this->assertTrue(Hash::check($password, $admin->getAuthPassword()));
        $this->assertNotSame('ChangeMe@12345', $password);
        $this->assertTrue($admin->must_change_password);
        $this->assertNull($admin->email_verified_at, 'The e-mail is proved by OTP at first login.');
        $this->assertNull($admin->mobile_verified_at);
    }

    /**
     * The printed password is the stored one, character for character. The console
     * formatter used to strip `\` before `<`/`>` and swallow `</>` or `<info>`,
     * which Str::password() can generate: about 4 passwords in 1,000 were shown
     * wrong and could never sign in (2026-10-26).
     */
    public function test_a_password_with_console_markup_characters_is_printed_exactly(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $crafted = 'Ab1\<x</>y\>Z<info>9';
        $real = app(UserProvisioningService::class);

        $this->partialMock(UserProvisioningService::class, function (MockInterface $mock) use ($real, $crafted): void {
            $mock->shouldReceive('provision')->once()->andReturnUsing(function (array $data) use ($real, $crafted): IssuedCredentials {
                $user = $real->provision($data)->user;
                $user->forceFill(['password' => Hash::make($crafted)])->save();

                return new IssuedCredentials($user, $crafted);
            });
        });

        [$code, $output] = $this->bootstrap();
        $this->assertSame(0, $code, $output);
        $this->assertSame($crafted, $this->printedPassword($output));
    }

    public function test_the_printed_password_signs_in_over_http(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [, $output] = $this->bootstrap();

        $this->post(route('login.store'), ['login' => 'superadmin@cgpsc.gov.in', 'password' => $this->printedPassword($output)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('verify.show'));
    }

    public function test_it_refuses_a_second_super_admin_and_recovers_with_a_reason(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $first = $this->printedPassword($this->bootstrap()[1]);

        [$code] = $this->bootstrap(['--email' => 'another@cgpsc.gov.in', '--mobile' => '9000000702']);
        $this->assertSame(1, $code);
        $this->assertSame(1, User::role(Role::Admin->value)->count());

        [$code] = $this->bootstrap(['--recover' => true, '--email' => 'superadmin@cgpsc.gov.in']);
        $this->assertSame(1, $code, 'A reason is required.');

        [$code, $output] = $this->bootstrap(['--recover' => true, '--email' => 'superadmin@cgpsc.gov.in', '--reason' => 'Lost on handover']);
        $this->assertSame(0, $code);
        $second = $this->printedPassword($output);

        $admin = User::query()->where('email', 'superadmin@cgpsc.gov.in')->sole();
        $this->assertFalse(Hash::check($first, $admin->getAuthPassword()));
        $this->assertTrue(Hash::check($second, $admin->getAuthPassword()));
        $this->assertDatabaseHas('activity_timelines', ['event' => 'user.password_recovered', 'subject_id' => $admin->id]);
    }

    public function test_it_validates_and_needs_the_roles(): void
    {
        [$code] = $this->bootstrap();
        $this->assertSame(1, $code, 'Roles not seeded.');

        $this->seed(RolesAndPermissionsSeeder::class);
        [$code] = $this->bootstrap(['--email' => 'someone@gmail.com']);
        $this->assertSame(1, $code);
        $this->assertSame(0, User::query()->count());
    }
}
