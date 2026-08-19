<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Services\NotificationCentre;
use App\Domain\Proceeding\Events\ProceedingDocumentsPublished;
use App\Enums\Role;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The in-app copy of "your DPC's documents have been released" — M11.
 *
 * **Publication is not a workflow transition** (M7 Phase 4's own reasoning): the
 * decision was taken at approval and the DPC is in the same state before and
 * after, so it has an event of its own rather than a fake transition. That is why
 * this is a second listener rather than something `RecordInAppNotification`
 * handles — the two events genuinely differ.
 *
 * This is the notification that matters most to a department. Everything before it
 * is procedural; this is the one that says the signed promotion list is available.
 *
 * M7's `SendDocumentPublicationNotification` is untouched.
 */
class RecordPublicationNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly NotificationCentre $centre) {}

    public function handle(ProceedingDocumentsPublished $event): void
    {
        try {
            $count = count($event->documentUuids);

            $this->centre->notify(
                $event->departmentId,
                [Role::AuthorizedOfficer->value, Role::AdministrativeHead->value],
                [],
                [
                    'workflow' => 'dpc_proceeding',
                    'event' => 'documents_published',
                    'title' => $event->dpcCode === null
                        ? 'The signed documents for your DPC have been released'
                        : "The signed documents for DPC {$event->dpcCode} have been released",
                    'body' => sprintf(
                        '%d document(s) published · proceeding %s',
                        $count,
                        $event->proceedingUuid,
                    ),
                ],
            );
        } catch (Throwable $e) {
            // Publication must not fail because a notification did — the same rule
            // the SMS listener follows, for the same reason.
            Log::warning('In-app publication notification could not be recorded.', [
                'proceeding_uuid' => $event->proceedingUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
