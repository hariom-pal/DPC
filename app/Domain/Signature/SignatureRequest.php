<?php

declare(strict_types=1);

namespace App\Domain\Signature;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Input to a SignatureProvider: who is signing, the document bytes, and the
 * business record the signature attests to.
 */
final class SignatureRequest
{
    /**
     * `$reference` is a one-time value the provider must echo back, so the result
     * is tied to this request and no other (2026-10-27).
     *
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly User $signer,
        public readonly Model $signable,
        public readonly string $documentContents,
        public readonly string $documentName,
        public readonly array $context = [],
        public readonly ?string $reference = null,
    ) {}

    public function documentDigest(): string
    {
        return hash('sha256', $this->documentContents);
    }
}
