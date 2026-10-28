<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Events;

use App\Domain\Authentication\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An account was given a system-issued temporary password — on creation or on a
 * reset.
 *
 * **Carries no password** (business decision, 2026-10-26). The temporary password
 * is shown once, on screen, to the administrator who issued it; it is never sent by
 * e-mail or SMS, and an event that held it would put it in the queue payload.
 */
class CredentialsIssued
{
    use Dispatchable;
    use SerializesModels;

    public const REASON_CREATED = 'created';

    public const REASON_RESET = 'reset';

    public function __construct(
        public readonly User $user,
        public readonly string $reason = self::REASON_CREATED,
    ) {}
}
