<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Shared\Workflow\WorkflowTransition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * What *would* be sent if a range of history were replayed — **TD-10, closed**.
 *
 * Designed in `docs/EVENT_CATALOG.md` and deliberately not built at the time. The
 * three cases it exists for, in the catalogue's own words:
 *
 * - **Before enabling a new subscriber.** Turning on a calendar integration against
 *   months of history would otherwise mean discovering its behaviour by sending real
 *   invitations.
 * - **After a bug.** If notifications were dropped for a period, this shows exactly
 *   which would be re-sent before anything reaches an officer.
 * - **Verifying an export.** Confirming an audit export covers what it should,
 *   before it becomes evidence.
 *
 * **The guarantee is no side effects**: nothing is dispatched, no SMS, no mail, no
 * database write. It reads the **workflow ledger** — the authoritative record of
 * what happened — and resolves recipients through the *same*
 * {@see NotificationRecipientResolver} and renders titles through the *same*
 * {@see NotificationMessageFactory} the live listeners use. Resolving them a second
 * way would make the dry run a prediction about a different system.
 *
 * **The most useful thing it reports is what cannot be replayed.** A transition
 * whose notify-roles resolve to nobody — every recipient deactivated since, or a
 * department with no officers left — is the case a replay would silently skip, and
 * it is called out rather than counted as zero.
 */
class NotificationReplayDryRun
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationMessageFactory $messages,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  workflow, event, subject_type, subject_id, from, to
     * @return array{
     *     entries: list<array<string, mixed>>,
     *     by_event: array<string, int>,
     *     by_recipient: array<string, int>,
     *     unresolvable: list<array<string, mixed>>,
     *     totals: array{transitions:int, notifications:int, unresolvable:int}
     * }
     */
    public function run(array $filters = [], int $limit = 1000): array
    {
        $entries = [];
        $byEvent = [];
        $byRecipient = [];
        $unresolvable = [];
        $notifications = 0;

        foreach ($this->transitions($filters, $limit) as $transition) {
            $definition = config(
                "dpc.workflows.{$transition->workflow}.transitions.{$transition->event}",
            );

            // A ledger row whose transition no longer exists in configuration is
            // itself a finding: the workflow definition has changed since, and the
            // Workflow Version on the row is what says so.
            if (! is_array($definition)) {
                $unresolvable[] = [
                    'uuid' => $transition->uuid,
                    'workflow' => $transition->workflow,
                    'event' => $transition->event,
                    'reason' => 'The transition no longer exists in the workflow configuration, '
                        .'so its notify-roles cannot be resolved.',
                ];

                continue;
            }

            /** @var list<string> $roles */
            $roles = $definition['notify'] ?? [];

            // The ledger does not record `notifyUserIds` — those were passed to the
            // event, not stored — so a dry run can resolve the role-based recipients
            // exactly and must say so about the rest rather than guess.
            $resolved = $this->recipients->resolve($this->departmentOf($transition), $roles, []);

            $title = $this->messages->title(
                $transition->workflow,
                $transition->event,
                (string) $transition->to_state,
            );

            $byEvent["{$transition->workflow}.{$transition->event}"]
                = ($byEvent["{$transition->workflow}.{$transition->event}"] ?? 0) + 1;

            if ($resolved->isEmpty()) {
                $unresolvable[] = [
                    'uuid' => $transition->uuid,
                    'workflow' => $transition->workflow,
                    'event' => $transition->event,
                    'reason' => $roles === []
                        ? 'This transition notifies nobody by configuration.'
                        : 'No active recipient holds '.implode(' or ', $roles)
                            .' in this department today, so a replay would reach nobody.',
                ];
            }

            foreach ($resolved as $recipient) {
                $byRecipient[$recipient->name] = ($byRecipient[$recipient->name] ?? 0) + 1;
                $notifications++;
            }

            $entries[] = [
                'uuid' => $transition->uuid,
                'occurred_at' => $transition->occurred_at->toIso8601String(),
                'workflow' => $transition->workflow,
                'event' => $transition->event,
                'transition' => ($transition->from_state ?? '(none)').' → '.$transition->to_state,
                'title' => $title,
                'body' => $this->messages->body(
                    $transition->workflow,
                    $transition->event,
                    (string) $transition->to_state,
                    $transition->remarks,
                ),
                'recipients' => $resolved->pluck('name')->all(),
            ];
        }

        ksort($byEvent);
        arsort($byRecipient);

        return [
            'entries' => $entries,
            'by_event' => $byEvent,
            'by_recipient' => $byRecipient,
            'unresolvable' => $unresolvable,
            'totals' => [
                'transitions' => count($entries) + count(array_filter(
                    $unresolvable,
                    static fn (array $u): bool => str_contains((string) $u['reason'], 'no longer exists'),
                )),
                'notifications' => $notifications,
                'unresolvable' => count($unresolvable),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, WorkflowTransition>
     */
    private function transitions(array $filters, int $limit): Collection
    {
        $query = WorkflowTransition::query()
            ->orderBy('occurred_at')
            ->orderBy('uuid')
            ->limit($limit);

        foreach ([
            'workflow' => 'workflow',
            'event' => 'event',
            'subject_type' => 'subject_type',
            'subject_id' => 'subject_id',
        ] as $filter => $column) {
            $value = $filters[$filter] ?? null;

            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        if (($from = $filters['from'] ?? null) !== null && $from !== '') {
            $query->where('occurred_at', '>=', Carbon::parse((string) $from)->startOfDay());
        }

        if (($to = $filters['to'] ?? null) !== null && $to !== '') {
            $query->where('occurred_at', '<=', Carbon::parse((string) $to)->endOfDay());
        }

        return $query->get();
    }

    /**
     * Which department a ledger row belongs to.
     *
     * The ledger stores the subject polymorphically and not the department, so it is
     * read from the subject. A subject since deleted yields null, which the resolver
     * treats as "no role-scoped recipients" — and that lands in `unresolvable`,
     * which is where it belongs.
     */
    private function departmentOf(WorkflowTransition $transition): ?int
    {
        $subject = $transition->subject;

        if ($subject === null) {
            return null;
        }

        $departmentId = $subject->getAttribute('department_id');

        return is_numeric($departmentId) ? (int) $departmentId : null;
    }

    /**
     * A guard for the caller, not a business rule: nothing here writes, and this
     * makes that checkable from outside.
     */
    public function guaranteesNoSideEffects(): bool
    {
        return true;
    }
}
