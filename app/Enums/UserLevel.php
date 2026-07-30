<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Department account levels issued at registration (PPT S5).
 */
enum UserLevel: int
{
    case AuthorizedOfficer = 1;
    case AdministrativeHead = 2;

    public function label(): string
    {
        return match ($this) {
            self::AuthorizedOfficer => 'Level-1 (Authorized Officer)',
            self::AdministrativeHead => 'Level-2 (Administrative Head)',
        };
    }
}
