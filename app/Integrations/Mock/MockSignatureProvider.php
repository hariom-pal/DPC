<?php

declare(strict_types=1);

namespace App\Integrations\Mock;

use App\Domain\Signature\SignatureRequest;
use App\Domain\Signature\SignatureResult;
use App\Integrations\Contracts\SignatureProvider;
use Illuminate\Support\Str;

/**
 * Development signature provider. Produces a deterministic, clearly-marked
 * "signed" document plus synthetic certificate metadata so signing flows can
 * be exercised end-to-end before the CSC DigiSign API is available.
 */
class MockSignatureProvider implements SignatureProvider
{
    /**
     * The container key the eSign simulator sets for one signing (2026-10-28).
     */
    public const SIMULATED_NAME = 'esign.simulated_signer_name';

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
            // The mock asserts the portal name — or, from the eSign simulator
            // (non-production only), the name the tester typed: it proves the
            // wiring of the name check, never that a real eSign identity matched.
            signerName: app()->bound(self::SIMULATED_NAME) ? (string) app(self::SIMULATED_NAME) : $request->signer->name,
            requestReference: $request->reference,
            documentDigest: $request->documentDigest(),
        );
    }
}
