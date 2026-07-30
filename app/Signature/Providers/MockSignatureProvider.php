<?php

declare(strict_types=1);

namespace App\Signature\Providers;

use App\Signature\Contracts\SignatureProvider;
use App\Signature\SignatureRequest;
use App\Signature\SignatureResult;
use Illuminate\Support\Str;

/**
 * Development signature provider. Produces a deterministic, clearly-marked
 * "signed" document plus synthetic certificate metadata so signing flows can
 * be exercised end-to-end before the CSC DigiSign API is available.
 */
class MockSignatureProvider implements SignatureProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function sign(SignatureRequest $request): SignatureResult
    {
        $reference = 'MOCK-'.Str::upper(Str::random(16));
        $signedAt = now();

        $stamp = sprintf(
            "\n\n--- DIGITALLY SIGNED (MOCK) ---\nSigner: %s\nReference: %s\nSigned At: %s\n",
            $request->signer->name,
            $reference,
            $signedAt->toIso8601String(),
        );

        return new SignatureResult(
            provider: $this->name(),
            transactionReference: $reference,
            signedContents: $request->documentContents.$stamp,
            signedAt: $signedAt,
            certificateSubject: 'CN=ODPC Mock Signer,O=CGPSC',
            certificateSerial: (string) Str::uuid(),
            certificateIssuer: 'CN=ODPC Development CA,O=CGPSC',
            meta: ['mock' => true],
        );
    }
}
