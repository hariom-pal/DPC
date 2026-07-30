<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Domain\Signature\SignatureRequest;
use App\Domain\Signature\SignatureResult;

/**
 * Abstraction over the digital-signing mechanism.
 *
 * Development uses a mock provider; production will use CSC DigiSign / CSC
 * eSign. Business logic depends on SignatureService only — never on a concrete
 * provider — so swapping providers changes configuration, not application code.
 */
interface SignatureProvider
{
    public function name(): string;

    public function sign(SignatureRequest $request): SignatureResult;
}
