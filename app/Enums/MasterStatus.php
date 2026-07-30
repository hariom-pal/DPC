<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle status shared by all reference masters (architect directive).
 */
enum MasterStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Bootstrap contextual colour for the status badge.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'text-bg-secondary',
            self::Active => 'text-bg-success',
            self::Inactive => 'text-bg-warning',
            self::Archived => 'text-bg-dark',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
