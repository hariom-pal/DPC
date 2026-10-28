<?php

declare(strict_types=1);

namespace App\Domain\Department\Exceptions;

use RuntimeException;

/**
 * An invitation could not be issued, sent or accepted; the message says why, in
 * words a Portal Super Admin or an invited officer can act on.
 */
final class InvitationRefused extends RuntimeException {}
