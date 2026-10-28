<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Otp;

/**
 * `<OtpRes ret="" code="" txn="" err="" ts="" info=""/>`, read. Carries no identity.
 */
final readonly class OtpResponse
{
    public function __construct(
        public bool $sent,
        public ?string $txn,
        public ?string $code,
        public ?string $errorCode,
    ) {}
}
