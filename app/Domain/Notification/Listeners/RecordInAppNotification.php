<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Services\NotificationCentre;
use App\Domain\Notification\Services\NotificationMessageFactory;
use App\Domain\Shared\Events\WorkflowTransitioned;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the in-app copy of a workflow notification — M11.
 *
 * **A second listener on the same event, not a change to the first.** M6's
 * `SendWorkflowNotification` is untouched: this is AD-20's pattern applied to an
 * event subscriber rather than to a service — behaviour is added to a completed
 * milestone by decoration, never by edit. Turning this listener off leaves SMS and
 * e-mail behaving exactly as they did.
 *
 * **A transition must never fail because a notification did** (architect
 * directive, M6 Phase 2, where a failing notification once rolled back a completed
 * transition). Same rule here, so a database error writing the feed is logged and
 * swallowed. It is `afterCommit` for the same reason the SMS listener is: on a
 * synchronous queue this would otherwise run inside the workflow's transaction.
 */
class RecordInAppNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationCentre $centre,
        private readonly NotificationMessageFactory $messages,
    ) {}

    public function handle(WorkflowTransitioned $event): void
    {
        try {
            $this->centre->notify(
                $event->departmentId,
                $event->notifyRoles,
                $event->notifyUserIds,
                [
                    'workflow' => $event->workflow,
                    'event' => $event->event,
                    'title' => $this->messages->title($event->workflow, $event->event, $event->toState),
                    'body' => $this->messages->body(
                        $event->workflow,
                        $event->event,
                        $event->toState,
                        $event->remarks,
                    ),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('In-app notification could not be recorded.', [
                'workflow' => $event->workflow,
                'event' => $event->event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
