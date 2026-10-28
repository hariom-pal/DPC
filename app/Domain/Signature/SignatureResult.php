<?php

declare(strict_types=1);

namespace App\Domain\Signature;

use Illuminate\Support\Carbon;

/**
 * Output of a SignatureProvider: the signed document bytes plus the
 * transaction / certificate metadata to persist for audit.
 */
final class SignatureResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $transactionReference,
        public readonly string $signedContents,
        public readonly Carbon $signedAt,
        public readonly ?string $certificateSubject = null,
        public readonly ?string $certificateSerial = null,
        public readonly ?string $certificateIssuer = null,
        public readonly array $meta = [],
        // From the provider's authenticated eSign response (2026-10-27): the name
        // the signer was authenticated as, the request reference echoed back,
        // and the digest of the document actually signed. Never from the browser.
        public readonly ?string $signerName = null,
        public readonly ?string $requestReference = null,
        public readonly ?string $documentDigest = null,
    ) {}
}
