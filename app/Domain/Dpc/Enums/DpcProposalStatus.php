<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

enum DpcProposalStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    public function isTerminal(): bool
    {
        return $this === self::Approved || $this === self::Cancelled;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'text-bg-secondary',
            self::Submitted => 'text-bg-info',
            self::Returned => 'text-bg-warning',
            self::Approved => 'text-bg-success',
            self::Cancelled => 'text-bg-dark',
        };
    }
}
