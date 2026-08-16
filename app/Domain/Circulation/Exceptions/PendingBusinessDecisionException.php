<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Exceptions;

use App\Domain\Circulation\ValueObjects\PendingBusinessDecision;
use RuntimeException;

/**
 * Raised when a request is well-formed and legitimate, but answering it would
 * require a business rule nobody has ruled on.
 *
 * **Deliberately not a `WorkflowException`.** A workflow exception says the
 * caller did something they may not do; this says the caller did something the
 * *system* cannot yet decide. Conflating the two would put "you are not
 * permitted" in front of a department that has done nothing wrong, and would
 * make the blocked cases indistinguishable from ordinary validation failures
 * in any report of what is waiting on CGPSC.
 *
 * The structured decision travels with the exception so a caller never has to
 * parse the message.
 */
class PendingBusinessDecisionException extends RuntimeException
{
    public function __construct(public readonly PendingBusinessDecision $decision)
    {
        parent::__construct($decision->summary());
    }
}
