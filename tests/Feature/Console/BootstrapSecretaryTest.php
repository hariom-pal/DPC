<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Authentication\Models\User;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `php artisan dpc:bootstrap-secretary` (2026-11-15): the default Secretary, CGPSC.
 */
class BootstrapSecretaryTest extends TestCase
{
    use RefreshDatabase;

    private function bootstrap(array $options = []): array
    {
        $code = Artisan::call('dpc:bootstrap-secretary', array_replace([
            '--name' => 'Secretary CGPSC', '--email' => 'secretary@cgpsc.gov.in', '--mobile' => '9000000301',
        ], $options));

        return [$code, Artisan::output()];
    }

    public function test_it_creates_the_secretary_once_with_a_password_shown_once(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$code, $output] = $this->bootstrap();
        $this->assertSame(0, $code, $output);
        preg_match('/Temporary password \(shown once, not stored\): (\S+)/', $output, $match);
        $this->assertNotEmpty($match, $output);

        $secretary = User::query()->where('email', 'secretary@cgpsc.gov.in')->sole();
        $this->assertTrue($secretary->hasRole(Role::Secretary->value));
        $this->assertTrue(Hash::check($match[1], $secretary->getAuthPassword()));
        $this->assertTrue($secretary->must_change_password);
        $this->assertSame('Secretary, CGPSC', $secretary->designation?->name);

        // A second run creates nothing.
        [$code, $output] = $this->bootstrap(['--email' => 'other@cgpsc.gov.in', '--mobile' => '9000000302']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('already exists', $output);
        $this->assertSame(1, User::role(Role::Secretary->value)->count());
    }

    public function test_it_refuses_a_non_official_e_mail(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$code] = $this->bootstrap(['--email' => 'someone@gmail.com']);
        $this->assertSame(1, $code);
        $this->assertSame(0, User::role(Role::Secretary->value)->count());
    }
}
