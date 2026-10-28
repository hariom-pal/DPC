<?php

declare(strict_types=1);

namespace App\Domain\Signature\Exceptions;

use RuntimeException;

/**
 * An eSign that did not prove what it must (2026-10-27): the provider failed,
 * the result does not belong to this request, the transaction was seen before,
 * or the authenticated signer's name is not the portal-registered name. Nothing
 * is stored and the workflow does not advance.
 */
class SignatureVerificationFailed extends RuntimeException
{
    public const ESIGN_FAILED = 'esign_failed';

    public const NAME_MISMATCH = 'name_mismatch';

    public const NOT_CORRELATED = 'not_correlated';

    public const REPLAYED = 'replayed';

    /**
     * `$assertedName`: the name the provider asserted, when it asserted one — shown
     * on the proposal's eSign notice (2026-10-28).
     */
    public function __construct(public readonly string $reason, public readonly ?string $assertedName = null)
    {
        parent::__construct((string) __('esign.errors.'.$reason));
    }
}
