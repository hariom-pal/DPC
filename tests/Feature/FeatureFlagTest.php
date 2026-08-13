<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authentication\Models\User;
use App\Enums\Feature;
use App\Enums\Role;
use App\Integrations\Contracts\DirectoryProvider;
use App\Integrations\Contracts\HrmsProvider;
use App\Integrations\Contracts\SignatureProvider;
use App\Integrations\Contracts\SmsSender;
use App\Integrations\CSC\CscDigiSignProvider;
use App\Integrations\HRMS\RealHrmsProvider;
use App\Integrations\Mock\LogSmsSender;
use App\Integrations\Mock\MockHrmsProvider;
use App\Integrations\Mock\MockSignatureProvider;
use App\Integrations\Mock\SeedDirectoryProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Centralized feature flags and the Provider Pattern (architect ruling,
 * 2026-08-13).
 */
class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private function rebind(): void
    {
        // Bindings are resolved from config at container-resolution time, so
        // forget any cached instance after changing a flag.
        foreach ([SignatureProvider::class, HrmsProvider::class, SmsSender::class, DirectoryProvider::class] as $contract) {
            $this->app->forgetInstance($contract);
        }
    }

    public function test_every_integration_flag_is_disabled_by_default(): void
    {
        // The shipped default must be "nothing connected": that is the UAT and
        // early-production configuration, and what CI runs.
        $defaults = require config_path('features.php');

        foreach ([Feature::Hrms, Feature::CscDigiSign, Feature::SmsGateway, Feature::EmailGateway, Feature::S3Storage, Feature::LgdSync] as $feature) {
            $this->assertFalse(
                (bool) $defaults[$feature->value],
                "{$feature->envKey()} must default to false.",
            );
        }

        // Notifications are an internal capability, not an external dependency.
        $this->assertTrue((bool) $defaults[Feature::Notifications->value]);
    }

    public function test_every_flag_has_a_config_entry_and_a_documented_fallback(): void
    {
        $config = require config_path('features.php');

        foreach (Feature::all() as $feature) {
            $this->assertArrayHasKey($feature->value, $config, "{$feature->value} has no entry in config/features.php.");
            $this->assertNotSame('', $feature->label());
            $this->assertNotSame('', $feature->fallbackBehaviour(), "{$feature->value} must document what happens while it is off.");
            $this->assertSame('FEATURE_'.strtoupper($feature->value), $feature->envKey());
        }
    }

    public function test_providers_follow_their_flag(): void
    {
        config(['features.csc_digisign' => false, 'features.hrms' => false, 'features.sms_gateway' => false, 'features.lgd_sync' => false]);
        $this->rebind();

        $this->assertInstanceOf(MockSignatureProvider::class, app(SignatureProvider::class));
        $this->assertInstanceOf(MockHrmsProvider::class, app(HrmsProvider::class));
        $this->assertInstanceOf(LogSmsSender::class, app(SmsSender::class));
        $this->assertInstanceOf(SeedDirectoryProvider::class, app(DirectoryProvider::class));

        config(['features.csc_digisign' => true, 'features.hrms' => true]);
        $this->rebind();

        $this->assertInstanceOf(CscDigiSignProvider::class, app(SignatureProvider::class));
        $this->assertInstanceOf(RealHrmsProvider::class, app(HrmsProvider::class));
    }

    public function test_hrms_disabled_reports_unavailable_and_never_fabricates_an_employee(): void
    {
        config(['features.hrms' => false]);
        $this->rebind();

        $provider = app(HrmsProvider::class);

        $this->assertFalse($provider->isAvailable());
        // A plausible-looking fake officer flowing into a promotion proposal
        // would be far worse than an empty result.
        $this->assertNull($provider->findEmployee('EMP-1'));
    }

    public function test_enabling_an_unimplemented_integration_fails_loudly(): void
    {
        // Turning on a flag whose provider has not been written must break
        // immediately and visibly, not degrade into silently wrong behaviour.
        config(['features.hrms' => true]);
        $this->rebind();

        $this->expectException(\RuntimeException::class);
        app(HrmsProvider::class)->findEmployee('EMP-1');
    }

    public function test_sms_and_lgd_flags_refuse_to_enable_without_an_implementation(): void
    {
        config(['features.sms_gateway' => true]);
        $this->rebind();
        try {
            app(SmsSender::class);
            $this->fail('Enabling FEATURE_SMS_GATEWAY without a provider must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('FEATURE_SMS_GATEWAY', $e->getMessage());
        }

        config(['features.sms_gateway' => false, 'features.lgd_sync' => true]);
        $this->rebind();
        try {
            app(DirectoryProvider::class);
            $this->fail('Enabling FEATURE_LGD_SYNC without a provider must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('FEATURE_LGD_SYNC', $e->getMessage());
        }
    }

    public function test_storage_disks_follow_the_s3_flag(): void
    {
        $withFlag = static function (bool $enabled): array {
            putenv('FEATURE_S3_STORAGE='.($enabled ? 'true' : 'false'));
            $_ENV['FEATURE_S3_STORAGE'] = $enabled ? 'true' : 'false';

            return require config_path('dpc.php');
        };

        $off = $withFlag(false);
        $this->assertSame('local', $off['signature']['disk']);
        $this->assertSame('local', $off['documents']['disk']);
        $this->assertSame('local', $off['imports']['disk']);

        $on = $withFlag(true);
        $this->assertSame('s3', $on['signature']['disk']);
        $this->assertSame('s3', $on['documents']['disk']);
        $this->assertSame('s3', $on['imports']['disk']);

        putenv('FEATURE_S3_STORAGE=false');
        $_ENV['FEATURE_S3_STORAGE'] = 'false';
    }

    public function test_integration_status_screen_is_admin_only_and_reports_bound_providers(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.integrations'))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.integrations'))
            ->assertOk()
            ->assertSee('Integration Status')
            ->assertSee('FEATURE_CSC_DIGISIGN')
            ->assertSee('FEATURE_HRMS')
            ->assertSee('Resolved Providers')
            // The check that confirms production is not signing with the mock.
            ->assertSee('mock');
    }

    public function test_a_new_flag_appears_on_the_status_screen_without_being_registered_twice(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        $response = $this->actingAs($admin)->get(route('admin.integrations'))->assertOk();

        foreach (Feature::all() as $feature) {
            $response->assertSee($feature->envKey());
        }
    }
}
