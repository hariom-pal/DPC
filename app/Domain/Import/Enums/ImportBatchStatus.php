<?php

declare(strict_types=1);

namespace App\Domain\Import\Enums;

/**
 * Lifecycle of one uploaded file through the architect-directed pipeline:
 * Preview -> Validation -> Error Report -> Commit.
 *
 * `Previewed` is the only state a batch can be committed from — an upload
 * that has not been parsed and shown to the officer can never reach the
 * proposal.
 *
 * **Reserved: `Expired`.** The architect has flagged a future state for
 * previewed batches that were never acted on (directive, 2026-08-13), with no
 * implementation required now — so no unused case is declared here, since a
 * case with no transition into it would be dead code on a screen we demo.
 * What is prepared is everything that would otherwise need changing: the
 * column is `string(20)` (room for `expired`), `isCommittable()` allows only
 * `Previewed` so an expired batch is refused by default rather than by a new
 * rule, and `isTerminal()` is the single place a terminal state is declared.
 * Adding the case later is a one-line change plus whatever sweeps batches.
 */
enum ImportBatchStatus: string
{
    case Pending = 'pending';
    case Previewed = 'previewed';
    case Committed = 'committed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Uploading',
            self::Previewed => 'Awaiting Confirmation',
            self::Committed => 'Committed',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'text-bg-secondary',
            self::Previewed => 'text-bg-warning',
            self::Committed => 'text-bg-success',
            self::Cancelled => 'text-bg-secondary',
            self::Failed => 'text-bg-danger',
        };
    }

    public function isCommittable(): bool
    {
        return $this === self::Previewed;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Committed, self::Cancelled, self::Failed], true);
    }
}
