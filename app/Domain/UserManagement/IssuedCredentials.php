<?php

declare(strict_types=1);

namespace App\Domain\UserManagement;

use App\Domain\Authentication\Models\User;
use SensitiveParameter;

/**
 * A system-issued temporary password, held only long enough to be shown once on
 * the issuing administrator's screen (business decision, 2026-10-26). Never
 * persisted, logged, queued or sent; only its hash is stored on the user.
 */
final readonly class IssuedCredentials
{
    public function __construct(
        public User $user,
        #[SensitiveParameter] public string $temporaryPassword,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['user' => $this->user->id, 'temporaryPassword' => '[redacted]'];
    }
}
