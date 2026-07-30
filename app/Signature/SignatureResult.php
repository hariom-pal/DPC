<?php

declare(strict_types=1);

namespace App\Signature;

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
    ) {}
}
