<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Application roles (RBAC via spatie/laravel-permission).
 *
 * Derived from the PPT actors: Authorized Officer (Level-1), Administrative
 * Head (Level-2), CGPSC Chairman, CGPSC Member, and the individual under
 * consideration. "Admin" (CGPSC/IT admin) is an approved addition not shown
 * in the PPT — it provisions Chairman/Member accounts and master data.
 */
enum Role: string
{
    case Admin = 'admin';
    case AuthorizedOfficer = 'authorized_officer';
    case AdministrativeHead = 'administrative_head';
    case Chairman = 'chairman';
    case Member = 'member';
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'CGPSC Admin',
            self::AuthorizedOfficer => 'Authorized Officer',
            self::AdministrativeHead => 'Administrative Head',
            self::Chairman => 'Chairman, CGPSC',
            self::Member => 'Member, CGPSC',
            self::Individual => 'Individual under Consideration',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /**
     * Roles a CGPSC Admin may provision directly (AO/AH come via department
     * registration; Individual via self-inspection).
     *
     * @return list<self>
     */
    public static function provisionable(): array
    {
        return [self::Admin, self::Chairman, self::Member];
    }

    /**
     * @return list<string>
     */
    public static function provisionableValues(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::provisionable());
    }
}
