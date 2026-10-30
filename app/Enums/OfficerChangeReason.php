<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a Department Admin reassigns or releases an officer (MoM of 2026-10-29):
 * chosen from this list, never typed.
 */
enum OfficerChangeReason: string
{
    case Transfer = 'transfer';
    case Dismissal = 'dismissal';
    case Retirement = 'retirement';
    case Death = 'death';
    case OnLeave = 'on_leave';
    case Suspended = 'suspended';
    case NotWilling = 'not_willing';
    case OfficialPermission = 'official_permission';

    public function label(): string
    {
        return (string) __('allotments.reasons.'.$this->value);
    }

    /**
     * A stored reason as shown: a listed one by its label, an older free-text
     * one as written.
     */
    public static function describe(?string $stored): ?string
    {
        return $stored === null ? null : (self::tryFrom($stored)?->label() ?? $stored);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }
}
