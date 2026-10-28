<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Exceptions;

use RuntimeException;

/**
 * A department assignment could not be made, changed or ended; the message says why.
 */
final class AssignmentRefused extends RuntimeException {}
