<?php

declare(strict_types=1);

namespace App\Integrations\Mock;

use App\Integrations\Aadhaar\AadhaarOtpRequest;
use App\Integrations\Aadhaar\AadhaarOtpVerification;
use App\Integrations\Contracts\AadhaarOtpProvider;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Demonstration Aadhaar OTP: any well-formed Aadhaar number is "sent" a code,
 * and the code is the configured mock code (`DPC_AADHAAR_OTP_MOCK_CODE`,
 * default 123456). Nothing leaves the server.
 */
class MockAadhaarOtpProvider implements AadhaarOtpProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function requestOtp(#[SensitiveParameter] string $aadhaar): AadhaarOtpRequest
    {
        return new AadhaarOtpRequest(
            sent: true,
            txn: 'MOCK'.Str::upper(Str::random(16)),
            message: 'Demonstration OTP: enter '.$this->code().'.',
        );
    }

    public function verifyOtp(#[SensitiveParameter] string $aadhaar, string $txn, #[SensitiveParameter] string $otp): AadhaarOtpVerification
    {
        return hash_equals($this->code(), $otp) && str_starts_with($txn, 'MOCK')
            ? new AadhaarOtpVerification(verified: true, message: 'Aadhaar OTP verified.')
            : new AadhaarOtpVerification(verified: false, message: 'The Aadhaar OTP is incorrect.', errorCode: '400');
    }

    private function code(): string
    {
        return (string) config('dpc.aadhaar_otp.mock_code', '123456');
    }
}
