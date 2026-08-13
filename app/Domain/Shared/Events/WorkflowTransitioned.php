<?php

declare(strict_types=1);

namespace App\Domain\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * `notifyRoles` resolves recipients by role *within the subject's department*.
 * That cannot reach a CGPSC Chairman or Member, who hold no department, so
 * `notifyUserIds` names specific users. Added for M6 (allotment notifies the
 * allotted Member); optional, so every existing caller is unaffected.
 */
class WorkflowTransitioned
{
    use Dispatchable;

    /**
     * Shape of this event's payload. **Version 1** is the baseline.
     *
     * Bump when the meaning of an existing property changes, never when one is
     * added: a subscriber written against version 1 — a notifier today, a
     * calendar integration tomorrow — must keep working against an event that
     * merely gained a field.
     */
    public const PAYLOAD_VERSION = 1;

    /**
     * @param  list<string>  $notifyRoles  department-scoped roles to notify
     * @param  list<int>  $notifyUserIds  specific users to notify regardless of department
     */
    public function __construct(
        public readonly string $workflow,
        public readonly string $event,
        public readonly string $toState,
        public readonly ?int $departmentId,
        public readonly array $notifyRoles,
        public readonly ?string $remarks = null,
        public readonly array $notifyUserIds = [],
        public readonly int $payloadVersion = self::PAYLOAD_VERSION,
    ) {}
}
