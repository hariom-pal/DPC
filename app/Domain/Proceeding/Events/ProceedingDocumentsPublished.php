<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Events;

use App\Domain\Proceeding\Listeners\SendDocumentPublicationNotification;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The Commission has released a DPC's documents to the department —
 * M7 Phase 4.
 *
 * **Not a workflow transition.** Publication changes no state: the decision
 * was taken at approval and the DPC is in exactly the same place before and
 * after. Reusing `WorkflowTransitioned` to carry it would have the ledger
 * claim a status change that did not occur, which is what Directive 4
 * (Phase 2) forbids — so publication gets an event of its own.
 *
 * Carries the three permanent references (Directive 2) so a subscriber can act
 * without reading a business table.
 *
 * @see SendDocumentPublicationNotification
 */
class ProceedingDocumentsPublished
{
    use Dispatchable;

    /** Bumped only when the meaning of an existing property changes. */
    public const PAYLOAD_VERSION = 1;

    /**
     * @param  list<string>  $documentUuids
     */
    public function __construct(
        public readonly string $proceedingUuid,
        public readonly string $proposalUuid,
        public readonly ?string $finalCalculationUuid,
        public readonly array $documentUuids,
        public readonly ?int $departmentId,
        public readonly ?string $dpcCode,
        public readonly int $payloadVersion = self::PAYLOAD_VERSION,
    ) {}
}
