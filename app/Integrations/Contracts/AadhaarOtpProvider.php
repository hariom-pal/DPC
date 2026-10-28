<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Integrations\Aadhaar\AadhaarOtpRequest;
use App\Integrations\Aadhaar\AadhaarOtpVerification;
use SensitiveParameter;

/**
 * Aadhaar OTP authentication of an officer at department registration
 * (business decision, 2026-10-22).
 *
 * Two calls, because a person reads a text message in between: UIDAI sends a
 * code to the mobile registered against the Aadhaar, and the code then travels
 * back in an authentication request. The answer is yes or no — no identity is
 * returned, and the Aadhaar number is never stored ("CGPSC do not capture
 * Aadhaar").
 *
 * Which provider answers is FEATURE_UIDAI_AADHAAR_OTP: off, the mock; on, UIDAI
 * over the CSC authentication API. Calling code depends on this interface only.
 */
interface AadhaarOtpProvider
{
    public function name(): string;

    /**
     * Ask UIDAI to send a code to the mobile registered against this Aadhaar.
     *
     * @param  string  $aadhaar  twelve digits
     */
    public function requestOtp(#[SensitiveParameter] string $aadhaar): AadhaarOtpRequest;

    /**
     * Authenticate the resident with the code they typed.
     *
     * @param  string  $txn  the transaction `requestOtp()` returned
     */
    public function verifyOtp(#[SensitiveParameter] string $aadhaar, string $txn, #[SensitiveParameter] string $otp): AadhaarOtpVerification;
}
