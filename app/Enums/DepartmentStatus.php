<?php

declare(strict_types=1);

namespace App\Enums;

enum DepartmentStatus: string
{
    case Unregistered = 'unregistered';
    case Registered = 'registered';
    case Active = 'active';

    public function label(): string
    {
        return match ($this) {
            self::Unregistered => 'Unregistered',
            self::Registered => 'Registered',
            self::Active => 'Active',
        };
    }
}
