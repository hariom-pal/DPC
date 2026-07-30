<?php

declare(strict_types=1);

namespace App\Integrations\CSC;

use App\Domain\Signature\SignatureRequest;
use App\Domain\Signature\SignatureResult;
use App\Integrations\Contracts\SignatureProvider;
use RuntimeException;

/**
 * Production signature provider backed by the CSC DigiSign / CSC eSign APIs.
 *
 * Placeholder until CSC shares the API contract and credentials. When wired,
 * only this class changes — SignatureService and all business modules remain
 * untouched.
 */
class CscDigiSignProvider implements SignatureProvider
{
    public function name(): string
    {
        return 'csc_digisign';
    }

    public function sign(SignatureRequest $request): SignatureResult
    {
        throw new RuntimeException(
            'CSC DigiSign provider is not yet configured. Provide the CSC eSign API contract and credentials to enable it.'
        );
    }
}
