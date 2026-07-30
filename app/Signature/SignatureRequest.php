<?php

declare(strict_types=1);

namespace App\Signature;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Input to a SignatureProvider: who is signing, the document bytes, and the
 * business record the signature attests to.
 */
final class SignatureRequest
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly User $signer,
        public readonly Model $signable,
        public readonly string $documentContents,
        public readonly string $documentName,
        public readonly array $context = [],
    ) {}
}
