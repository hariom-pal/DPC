<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Otp;

use SensitiveParameter;

/**
 * The encrypted PID block of one OTP authentication: `Data`, `Skey`, its `ci`
 * and `Hmac`, all base64. Ported from the CGMFPFED Welfare Platform (`PidBlock`).
 *
 * Opaque on purpose: `__toString()` and `__debugInfo()` are redactions, so a
 * `dd()`, a stack trace or a careless log line cannot write the block out.
 * UIDAI §3.2.1: data collected for authentication "must not be stored in the
 * devices or log files".
 */
final readonly class PidBlock
{
    public function __construct(
        #[SensitiveParameter] private string $data,
        #[SensitiveParameter] private string $skey,
        /** UIDAI encryption certificate expiry, `YYYYMMDD`. */
        private string $certificateIdentifier,
        #[SensitiveParameter] private string $hmac,
    ) {}

    public function data(): string
    {
        return $this->data;
    }

    public function sessionKey(): string
    {
        return $this->skey;
    }

    public function certificateIdentifier(): string
    {
        return $this->certificateIdentifier;
    }

    public function hmac(): string
    {
        return $this->hmac;
    }

    public function __toString(): string
    {
        return '[pid-block redacted]';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['data' => '[redacted]', 'skey' => '[redacted]', 'hmac' => '[redacted]', 'ci' => $this->certificateIdentifier];
    }
}
