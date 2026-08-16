<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Enums;

/**
 * Generation status of a circulation document — M9 Phase 3.
 *
 * **M9's own, rather than M7's `DocumentStatus`.** Reading a frozen enum would
 * be safe, but it would leave M9 unable to add a case it later needed without
 * opening M7 — the exact bind AQ-22 was ruled to avoid. The cases are
 * deliberately the same three, because the pattern is the same.
 *
 * `Pending` exists because **the row is created before the file is written**:
 * a generation that never completes then leaves visible evidence of an
 * expected-but-absent legal document rather than silence.
 */
enum CirculationDocumentStatus: string
{
    case Pending = 'pending';
    case Generated = 'generated';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Generating',
            self::Generated => 'Generated',
            self::Failed => 'Failed',
        };
    }
}
