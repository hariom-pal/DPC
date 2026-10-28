<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * An identity action could not be taken; the message says why.
 */
final class IdentityRefused extends RuntimeException {}
