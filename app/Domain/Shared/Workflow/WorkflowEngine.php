<?php

declare(strict_types=1);

namespace App\Domain\Shared\Workflow;

use App\Domain\Authentication\Models\User;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Configuration-driven workflow engine. Validates transition legality + actor
 * role from `config('dpc.workflows.*')`, and records every transition as a
 * ledger entry + timeline entry + notification event.
 */
class WorkflowEngine
{
    /**
     * The shape of the state machines themselves — which transitions exist and what
     * each means.
     *
     * **TD-13, closed 2026-08-19.** `docs/EVENT_CATALOG.md` declares three
     * independent versions: Schema Version and Payload Version each had a constant
     * in the code they describe, while Workflow Version existed **only in prose**.
     * A version that lives only in documentation can drift from the thing it
     * versions, and the drift is undetectable — which is the whole failure mode
     * versioning exists to prevent.
     *
     * It is recorded on every ledger row, so a stored transition says which
     * definition of the state machine produced it. That matters when a transition's
     * *meaning* changes rather than its name: two rows reading `approve` are not
     * necessarily the same act, and without this there is nothing to tell them
     * apart.
     *
     * **Bump when a transition is added, removed, or its meaning changes** — the
     * rule the catalogue already states. Currently 2: M7 added the
     * `dpc_proceeding` workflow.
     */
    public const WORKFLOW_VERSION = 2;

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
     * `$properties` are merged into the timeline entry. Domain detail recorded
     * there is what makes workflow history reproducible from timeline records
     * alone (architect directive) rather than by reading current state.
     *
     * `$notifyUserIds` names recipients a role-plus-department query cannot
     * reach — a CGPSC Chairman or Member holds no department.
     *
     * @param  array{from:list<string>,to:string,role:string,signature:bool,notify:list<string>}  $def
     * @param  array<string, mixed>  $properties
     * @param  list<int>  $notifyUserIds
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
        array $properties = [],
        array $notifyUserIds = [],
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
            // Additive: `meta` is a JSON column and nothing reads a fixed shape from
            // it, so recording the workflow version here cannot change how an
            // existing row is interpreted.
            'meta' => ['workflow_version' => self::WORKFLOW_VERSION],
            'occurred_at' => now(),
        ]);

        $this->timeline->record(
            "{$workflow}.{$event}",
            $subject,
            "{$workflow} {$event}: {$from} → {$def['to']}",
            array_merge(['from' => $from, 'to' => $def['to'], 'remarks' => $remarks], $properties),
            $actor,
        );

        // A workflow transition must never fail because a notification did
        // (architect directive). The ledger and timeline entries above are
        // already written; dispatch is the last step and is deliberately
        // isolated, so a broken listener — a dead SMS gateway, a misconfigured
        // mailer, a third-party subscriber — degrades to a logged warning
        // rather than rolling back a completed transition.
        try {
            WorkflowTransitioned::dispatch($workflow, $event, $def['to'], $departmentId, $def['notify'], $remarks, $notifyUserIds);
        } catch (Throwable $e) {
            Log::warning('Workflow notification dispatch failed; the transition itself is unaffected.', [
                'workflow' => $workflow,
                'event' => $event,
                'transition_uuid' => $transition->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        return $transition;
    }
}
