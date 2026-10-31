<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Enums;

/**
 * Lifecycle of a calculation run — M5 Phase 3.
 *
 * All four states are declared per architect directive (2026-08-13). Only
 * `Completed` is required today, and only `Draft` and `Completed` are produced
 * by the current code path: a run is created `Draft` and becomes `Completed`
 * when its results are written, so a run that is still `Draft` is one whose
 * calculation did not finish.
 *
 * `Cancelled` and `Archived` are declared but not yet reachable. That is a
 * deliberate exception to the project's no-unused-values rule, made on the
 * architect's explicit instruction to prepare them — and it is a safe one
 * here, because `isImmutable()` already treats every terminal state
 * correctly, so whichever phase starts producing them inherits the protection
 * rather than having to add it.
 */
enum CalculationStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return __("ui.enum.calculation_status.{$this->value}");
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'text-bg-secondary',
            self::Completed => 'text-bg-success',
            self::Cancelled => 'text-bg-dark',
            self::Archived => 'text-bg-light',
        };
    }

    /**
     * A completed calculation is frozen — no role, including an administrator,
     * may alter it (architect directive). Recalculation creates a new run.
     */
    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
