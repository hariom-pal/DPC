<?php

declare(strict_types=1);

namespace App\Domain\Shared\Workflow;

use App\Domain\Authentication\Models\User;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Configuration-driven workflow engine. Validates transition legality + actor
 * role from `config('dpc.workflows.*')`, and records every transition as a
 * ledger entry + timeline entry + notification event.
 */
class WorkflowEngine
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * @return array{from:list<string>,to:string,role:string,signature:bool,notify:list<string>}
     */
    public function assertAllowed(string $workflow, string $event, string $from, User $actor): array
    {
        /** @var array{from:list<string>,to:string,role:string,signature:bool,notify:list<string>}|null $def */
        $def = config("dpc.workflows.{$workflow}.transitions.{$event}");

        if (! is_array($def)) {
            throw new WorkflowException("Unknown transition [{$event}] for workflow [{$workflow}].");
        }

        if (! in_array($from, $def['from'], true)) {
            throw new WorkflowException("Transition [{$event}] is not allowed from state [{$from}].");
        }

        if (! $actor->hasRole($def['role'])) {
            throw new WorkflowException("You are not permitted to perform [{$event}].");
        }

        return $def;
    }

    /**
     * @param  array{from:list<string>,to:string,role:string,signature:bool,notify:list<string>}  $def
     */
    public function record(
        Model $subject,
        string $workflow,
        string $event,
        string $from,
        array $def,
        User $actor,
        Request $request,
        ?string $remarks = null,
        ?int $signatureId = null,
        ?int $departmentId = null,
    ): WorkflowTransition {
        $transition = WorkflowTransition::query()->create([
            'workflow' => $workflow,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'from_state' => $from,
            'to_state' => $def['to'],
            'actor_id' => $actor->getKey(),
            'remarks' => $remarks,
            'signature_id' => $signatureId,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'occurred_at' => now(),
        ]);

        $this->timeline->record(
            "{$workflow}.{$event}",
            $subject,
            "{$workflow} {$event}: {$from} → {$def['to']}",
            ['from' => $from, 'to' => $def['to'], 'remarks' => $remarks],
            $actor,
        );

        WorkflowTransitioned::dispatch($workflow, $event, $def['to'], $departmentId, $def['notify'], $remarks);

        return $transition;
    }
}
