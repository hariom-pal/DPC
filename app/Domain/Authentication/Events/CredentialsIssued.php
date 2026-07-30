<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Events;

use App\Domain\Authentication\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CredentialsIssued
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $temporaryPassword,
    ) {}
}
