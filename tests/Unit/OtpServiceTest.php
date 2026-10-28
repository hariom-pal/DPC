<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Exceptions\OtpThrottled;
use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\Authentication\Services\MobileVerificationWaiver;
use App\Domain\Authentication\Services\OtpService;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * OTP issue, verification, limits and the fixed development code (business
 * decision, 2026-10-26).
 */
class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): OtpService
    {
        return app(OtpService::class);
    }

    public function test_issued_otp_can_be_verified_once(): void
    {
        $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);

        $this->assertTrue($this->service()->verify('9407938480', OtpPurpose::Registration, '123456'));
        // Already consumed -> cannot be reused.
        $this->assertFalse($this->service()->verify('9407938480', OtpPurpose::Registration, '123456'));
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);

        $this->assertFalse($this->service()->verify('9407938480', OtpPurpose::Registration, '000000'));
    }

    // ------------------------------------------------------ the fixed code

    public function test_the_fixed_code_needs_local_or_testing_and_the_flag(): void
    {
        config(['dpc.otp.fixed' => ['enabled' => true, 'code' => '654321']]);
        $this->assertTrue($this->service()->fixedCodeInForce(), 'testing + flag');

        $this->app->detectEnvironment(fn (): string => 'local');
        $this->assertTrue($this->service()->fixedCodeInForce(), 'local + flag');

        config(['dpc.otp.fixed.enabled' => false]);
        $this->assertFalse($this->service()->fixedCodeInForce(), 'the flag is off');
    }

    /**
     * **Production never accepts a fixed code**, whatever the configuration says —
     * the exact accident of a copied `.env`. Nor does any environment outside the
     * allowlist (UAT joined it on 2026-10-26).
     */
    public function test_production_and_unknown_environments_ignore_a_configured_fixed_code(): void
    {
        config(['dpc.otp.fixed' => ['enabled' => true, 'code' => '654321', 'mobile' => true]]);

        foreach (['production', 'staging', 'prod'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);
            $this->assertFalse($this->service()->fixedCodeInForce(), $environment);
            $this->assertFalse($this->service()->fixedMobileCodeInForce(), $environment);

            $identifier = '98'.random_int(10000000, 99999999);
            $this->service()->issue($identifier, OtpChannel::Sms, OtpPurpose::Registration);
            $this->assertFalse($this->service()->verify($identifier, OtpPurpose::Registration, '654321'), $environment);
        }
    }

    /**
     * UAT accepts the fixed code when — and only when — its `.env` switches it on
     * (default off, 2026-10-26).
     */
    public function test_uat_accepts_the_fixed_code_only_when_switched_on(): void
    {
        $this->app->detectEnvironment(fn (): string => 'uat');

        config(['dpc.otp.fixed' => ['enabled' => false, 'code' => '123456', 'mobile' => false]]);
        $this->assertFalse($this->service()->fixedCodeInForce(), 'Off by default.');

        config(['dpc.otp.fixed.enabled' => true]);
        $this->assertTrue($this->service()->fixedCodeInForce());
        $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);
        $this->assertTrue($this->service()->verify('9407938480', OtpPurpose::Registration, '123456'));
    }

    /**
     * `DPC_OTP_FIXED_MOBILE` decides whether a mobile number is proved by the
     * fixed code or covered by a waiver while there is no SMS gateway.
     */
    public function test_the_mobile_switch_decides_whether_mobile_codes_are_usable(): void
    {
        config(['features.sms_gateway' => false, 'dpc.otp.fixed' => ['enabled' => true, 'code' => '123456', 'mobile' => false]]);
        $this->assertFalse($this->service()->mobileCodesUsable());
        $this->assertTrue(app(MobileVerificationWaiver::class)->available());

        config(['dpc.otp.fixed.mobile' => true]);
        $this->assertTrue($this->service()->mobileCodesUsable());
        $this->assertFalse(app(MobileVerificationWaiver::class)->available(), 'No waiver while the mobile can be proved.');

        config(['dpc.otp.fixed.enabled' => false]);
        $this->assertFalse($this->service()->mobileCodesUsable(), 'The mobile switch alone does nothing.');
    }

    public function test_a_wrong_code_is_still_refused_with_the_fixed_code(): void
    {
        config(['dpc.otp.fixed' => ['enabled' => true, 'code' => '654321']]);
        $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);

        $this->assertFalse($this->service()->verify('9407938480', OtpPurpose::Registration, '111111'));
        $this->assertTrue($this->service()->verify('9407938480', OtpPurpose::Registration, '654321'));
    }

    // --------------------------------------------- expiry, attempts, resend limits

    public function test_an_expired_code_is_refused(): void
    {
        $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);
        $this->travel((int) config('dpc.otp.ttl_seconds') + 1)->seconds();

        $this->assertFalse($this->service()->verify('9407938480', OtpPurpose::Registration, '123456'));
    }

    public function test_attempts_are_limited(): void
    {
        $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Registration);

        for ($i = 0; $i < (int) config('dpc.otp.max_attempts'); $i++) {
            $this->assertFalse($this->service()->verify('9407938480', OtpPurpose::Registration, '000000'));
        }

        $this->assertFalse($this->service()->verify('9407938480', OtpPurpose::Registration, '123456'), 'The right code after the limit is refused.');
    }

    public function test_a_resend_waits_for_the_cooldown_and_the_hourly_limit_holds(): void
    {
        $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Login);

        try {
            $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Login);
            $this->fail('A second code inside the cooldown must be refused.');
        } catch (OtpThrottled $e) {
            $this->assertFalse($e->hourlyLimit);
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
        }

        for ($i = 1; $i < (int) config('dpc.otp.max_sends_per_hour'); $i++) {
            $this->travel((int) config('dpc.otp.resend_cooldown_seconds') + 1)->seconds();
            $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Login);
        }

        $this->travel((int) config('dpc.otp.resend_cooldown_seconds') + 1)->seconds();
        $this->expectException(OtpThrottled::class);
        $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Login);
    }

    // ------------------------------------------------------------- delivery

    public function test_a_failed_email_withdraws_the_code_and_says_so(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP down'));

        try {
            $this->service()->issue('officer@cg.gov.in', OtpChannel::Email, OtpPurpose::Login);
            $this->fail('The delivery failure must surface.');
        } catch (OtpDeliveryFailed) {
            $this->assertSame(0, OtpVerification::query()->count(), 'A code nobody received is not left valid.');
        }
    }

    public function test_no_code_is_written_to_the_log_when_sms_is_unavailable(): void
    {
        config(['features.sms_gateway' => false]);
        Log::spy();

        $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Login);

        Log::shouldNotHaveReceived('info', [fn ($message, $context = []) => str_contains((string) $message.json_encode($context), '123456')]);
        Log::shouldHaveReceived('info')->with('SMS OTP not sent: the SMS gateway is not enabled.', ['purpose' => 'login']);
    }

    public function test_codes_checked_together_consume_nothing_unless_all_are_right(): void
    {
        $this->service()->issue('officer@cg.gov.in', OtpChannel::Email, OtpPurpose::Login);
        $this->service()->issue('9407938480', OtpChannel::Sms, OtpPurpose::Login);

        $this->assertFalse($this->service()->verifyAll([['officer@cg.gov.in', '123456'], ['9407938480', '000000']], OtpPurpose::Login));
        $this->assertSame(0, OtpVerification::query()->whereNotNull('consumed_at')->count(), 'The right e-mail code was not used up.');

        $this->assertTrue($this->service()->verifyAll([['officer@cg.gov.in', '123456'], ['9407938480', '123456']], OtpPurpose::Login));
        $this->assertSame(2, OtpVerification::query()->whereNotNull('consumed_at')->count());
    }
}
