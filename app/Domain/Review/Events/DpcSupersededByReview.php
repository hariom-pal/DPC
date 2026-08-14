<?php

declare(strict_types=1);

namespace App\Domain\Review\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Review DPC's result has been approved, and the DPC it corrects is now
 * superseded — M8 Phase 3.
 *
 * **Not a workflow transition on the original.** The original's own state is
 * untouched; what changed is that a *different* DPC now governs. Modelling it
 * as a transition would have the original's ledger claim a status change it
 * never underwent.
 *
 * Carries both DPC codes because the department needs to be told which
 * promotion list to stop relying on, and a code is what it knows them by.
 */
class DpcSupersededByReview
{
    use Dispatchable;

    /** Bumped only when the meaning of an existing property changes. */
    public const PAYLOAD_VERSION = 1;

    public function __construct(
        public readonly string $chainUuid,
        public readonly int $reviewNumber,
        public readonly string $originalProposalUuid,
        public readonly ?string $originalDpcCode,
        public readonly string $reviewProposalUuid,
        public readonly ?string $reviewDpcCode,
        public readonly ?int $departmentId,
        public readonly int $payloadVersion = self::PAYLOAD_VERSION,
    ) {}
}
