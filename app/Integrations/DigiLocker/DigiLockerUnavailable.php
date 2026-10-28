<?php

declare(strict_types=1);

namespace App\Integrations\DigiLocker;

use RuntimeException;

/**
 * A technical failure talking to DigiLocker — unreachable, refused, malformed
 * response, unknown code. Never counted as an identity mismatch.
 */
final class DigiLockerUnavailable extends RuntimeException {}
