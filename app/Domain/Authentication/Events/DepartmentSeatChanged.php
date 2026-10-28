<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Events;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Enums\Role;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Department registration moved an existing officer (business decision,
 * 2026-10-22): either posted them to a seat (`replaced` false) or took a seat
 * from them because another officer registered into it (`replaced` true).
 */
class DepartmentSeatChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Department $department,
        public readonly Role $role,
        public readonly bool $replaced,
    ) {}
}
