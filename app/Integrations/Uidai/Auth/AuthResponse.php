<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Auth;

/**
 * `<AuthRes ret="" code="" txn="" err="" ts="" actn="" info=""/>`, read.
 *
 * Only the yes/no and the codes that explain a no. `info` (which can carry a
 * UID token and hashed demographics) is deliberately not read: DPC needs none
 * of it and does not keep it.
 */
final readonly class AuthResponse
{
    public function __construct(
        public bool $authenticated,
        public ?string $txn,
        public ?string $code,
        public ?string $errorCode,
        public ?string $actionCode,
    ) {}
}
