<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Authentication\Services\OtpService;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_otp_can_be_verified_once(): void
    {
        $service = app(OtpService::class);

        $service->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);

        $this->assertTrue($service->verify('9407938480', OtpPurpose::Registration, '123456'));
        // Already consumed -> cannot be reused.
        $this->assertFalse($service->verify('9407938480', OtpPurpose::Registration, '123456'));
    }

    public function test_wrong_code_is_rejected(): void
    {
        $service = app(OtpService::class);

        $service->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);

        $this->assertFalse($service->verify('9407938480', OtpPurpose::Registration, '000000'));
    }

    // ------------------------------------------------- the demonstration OTP

    /**
     * `APP_ENV=local` issues the configured demonstration OTP, so Department
     * Registration can be completed on a machine with no SMS gateway, no mail
     * transport and possibly no queue worker.
     */
    public function test_the_demo_otp_is_issued_in_local(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['dpc.otp.demo_code' => '654321', 'dpc.otp.fake_code' => null]);

        $service = app(OtpService::class);
        $service->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);

        $this->assertTrue($service->verify('9407938480', OtpPurpose::Registration, '654321'));
    }

    /**
     * **The flow is not bypassed, only its randomness removed.** A wrong code is
     * still refused in `local`, the row is still consumed, and the attempt is
     * still counted — otherwise the demonstration would be showing something the
     * portal does not do.
     */
    public function test_a_wrong_code_is_still_refused_in_local(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['dpc.otp.demo_code' => '654321', 'dpc.otp.fake_code' => null]);

        $service = app(OtpService::class);
        $service->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);

        $this->assertFalse($service->verify('9407938480', OtpPurpose::Registration, '111111'));
        $this->assertTrue($service->verify('9407938480', OtpPurpose::Registration, '654321'));
    }

    /**
     * **Production is unchanged, and this is the assertion that keeps it so.**
     *
     * `DEMO_OTP` is read only when the environment is `local`. A production host
     * carrying the variable — by a copied `.env`, or by a deployment script that
     * templates every key — must still issue a random code. Asserted by setting
     * the config value and the environment to production together, which is the
     * exact accident being guarded against.
     */
    public function test_production_ignores_the_demo_otp_and_issues_a_random_code(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['dpc.otp.demo_code' => '654321', 'dpc.otp.fake_code' => null]);

        $service = app(OtpService::class);
        $service->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);

        $this->assertFalse($service->verify('9407938480', OtpPurpose::Registration, '654321'));
    }
}
