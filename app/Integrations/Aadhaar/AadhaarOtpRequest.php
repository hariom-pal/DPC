<?php

declare(strict_types=1);

namespace App\Integrations\Aadhaar;

/**
 * The result of asking for an Aadhaar OTP. Proves nothing about the resident:
 * `txn` only links this request to the verification the typed code travels in.
 */
final readonly class AadhaarOtpRequest
{
    public function __construct(
        public bool $sent,
        public string $txn,
        public string $message,
        public ?string $errorCode = null,
    ) {}
}
