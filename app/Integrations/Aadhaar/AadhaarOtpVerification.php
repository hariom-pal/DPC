<?php

declare(strict_types=1);

namespace App\Integrations\Aadhaar;

/**
 * The yes/no answer to an Aadhaar OTP authentication. Carries no identity.
 */
final readonly class AadhaarOtpVerification
{
    public function __construct(
        public bool $verified,
        public string $message,
        public ?string $errorCode = null,
    ) {}
}
