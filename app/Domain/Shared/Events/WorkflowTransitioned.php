<?php

declare(strict_types=1);

namespace App\Domain\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;

class WorkflowTransitioned
{
    use Dispatchable;

    /**
     * @param  list<string>  $notifyRoles
     */
    public function __construct(
        public readonly string $workflow,
        public readonly string $event,
        public readonly string $toState,
        public readonly ?int $departmentId,
        public readonly array $notifyRoles,
        public readonly ?string $remarks = null,
    ) {}
}
