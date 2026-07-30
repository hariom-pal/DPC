<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Services\OtpService;
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
}
