<?php

declare(strict_types=1);

namespace App\Domain\Allotment\Exceptions;

use RuntimeException;

/**
 * An allotment could not be made or changed; the message says why, in words the
 * Department Admin can act on.
 */
final class AllotmentRefused extends RuntimeException {}
