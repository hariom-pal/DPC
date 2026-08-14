<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Enums;

/**
 * Lifecycle of a generated document — M7 Phase 4.
 *
 * A row exists in `Pending` **before the file does**. A generation that never
 * runs then leaves visible evidence of an expected-but-absent legal document
 * rather than silence, which is the failure mode that matters: nobody notices
 * a document that was never promised.
 */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Generated = 'generated';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting Generation',
            self::Generated => 'Generated',
            self::Failed => 'Generation Failed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'text-bg-secondary',
            self::Generated => 'text-bg-success',
            self::Failed => 'text-bg-danger',
        };
    }
}
