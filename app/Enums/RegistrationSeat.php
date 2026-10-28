<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two seats department registration fills (business decision, 2026-10-22):
 * the Administrative Head (Level-2) and the Authorized Officer (Level-1). The
 * value is the form-field prefix.
 */
enum RegistrationSeat: string
{
    case AdministrativeHead = 'ah';
    case AuthorizedOfficer = 'ao';

    public function role(): Role
    {
        return match ($this) {
            self::AdministrativeHead => Role::AdministrativeHead,
            self::AuthorizedOfficer => Role::AuthorizedOfficer,
        };
    }

    public static function forRole(Role $role): ?self
    {
        foreach (self::cases() as $seat) {
            if ($seat->role() === $role) {
                return $seat;
            }
        }

        return null;
    }
}
