<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowTransition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Models\Audit;

/**
 * One readable view over the three records this system keeps of what happened.
 *
 * **M11. The reason it exists is stated in the enhancement backlog:** the audits
 * and the Activity Timeline have been written since M1 and were readable only
 * from the database — *"the first time anyone wants them is a contested
 * promotion, which is the worst time to discover there is no screen."*
 *
 * **Three records, deliberately not merged into one table.** They answer
 * different questions and have different standing, so this reads all three and
 * says which is which rather than flattening them into a single "log":
 *
 * | Source | Table | What it is |
 * |---|---|---|
 * | `workflow` | `workflow_transitions` | **The legal ledger.** Every state change, with the actor, the remarks, the signature it carried and the IP it came from. M6 made this the authoritative workflow record |
 * | `timeline` | `activity_timelines` | Domain events with an **actor snapshot** — who they were then, not who that user is now |
 * | `data` | `audits` | Model-level old → new values, from `owen-it/laravel-auditing` |
 *
 * **Unified by a UNION rather than by three queries merged in PHP**, because a
 * merge cannot be paginated honestly: page 2 of three separately-limited lists is
 * not the second page of anything. The union projects each source onto the same
 * nine columns and the database does the ordering and the limiting.
 */
class AuditTrailService
{
    public const SOURCE_WORKFLOW = 'workflow';

    public const SOURCE_TIMELINE = 'timeline';

    public const SOURCE_DATA = 'data';

    /**
     * @return array<string, string> source => label
     */
    public function sources(): array
    {
        return [
            self::SOURCE_WORKFLOW => 'Workflow ledger',
            self::SOURCE_TIMELINE => 'Activity timeline',
            self::SOURCE_DATA => 'Data changes',
        ];
    }

    /**
     * The trail, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, object> $page */
        $page = $this->ordered($filters)->paginate($perPage);

        return $page;
    }

    /**
     * The same trail, streamed in chunks — for an export that must not load the
     * whole table first.
     *
     * The point of an audit table is that it grows without bound, so an export
     * that reads it into memory is one that stops working exactly when the record
     * becomes valuable. Ordering is deterministic (`occurred_at`, then id within a
     * source), which is what makes offset paging safe here.
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, object>
     */
    public function stream(array $filters = [], int $chunk = 500): \Generator
    {
        $offset = 0;

        do {
            $rows = $this->ordered($filters)->offset($offset)->limit($chunk)->get();

            foreach ($rows as $row) {
                yield $row;
            }

            $offset += $chunk;
        } while ($rows->count() === $chunk);
    }

    /**
     * The union of every requested source, ordered newest first.
     *
     * @param  array<string, mixed>  $filters
     */
    private function ordered(array $filters): QueryBuilder
    {
        $requested = (string) ($filters['source'] ?? '');

        $parts = [];

        foreach (array_keys($this->sources()) as $source) {
            if ($requested !== '' && $requested !== $source) {
                continue;
            }

            $parts[] = $this->projection($source, $filters);
        }

        if ($parts === []) {
            // An unrecognised source filter yields nothing rather than silently
            // showing everything — the opposite mistake would present the whole
            // trail to somebody who asked for one slice of it.
            $parts[] = $this->projection(self::SOURCE_WORKFLOW, ['source_id' => -1]);
        }

        $union = array_shift($parts);

        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return DB::query()
            ->fromSub($union, 'trail')
            ->orderByDesc('occurred_at')
            ->orderByDesc('source_id');
    }

    /**
     * A single entry, in full — the record behind the row.
     */
    public function find(string $source, int $id): ?object
    {
        return match ($source) {
            self::SOURCE_WORKFLOW => WorkflowTransition::query()->with('actor')->find($id),
            self::SOURCE_TIMELINE => ActivityTimeline::query()->with('causer')->find($id),
            // No eager load: `user()` is declared on the package's `Audit` trait
            // as a runtime-configured morphTo, which static analysis cannot see.
            // This loads a single row, so there is nothing to eager-load anyway.
            self::SOURCE_DATA => Audit::query()->find($id),
            default => null,
        };
    }

    /**
     * How many entries each source holds — the figure that tells an operator
     * whether a record is being written at all.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            self::SOURCE_WORKFLOW => WorkflowTransition::query()->count(),
            self::SOURCE_TIMELINE => ActivityTimeline::query()->count(),
            self::SOURCE_DATA => Audit::query()->count(),
        ];
    }

    /**
     * The distinct subject types present, so the filter offers what exists
     * rather than a hardcoded list that goes stale as milestones are added.
     *
     * @return list<string>
     */
    public function subjectTypes(): array
    {
        /** @var list<string> $types */
        $types = collect()
            ->merge(WorkflowTransition::query()->distinct()->pluck('subject_type'))
            ->merge(ActivityTimeline::query()->distinct()->pluck('subject_type'))
            ->merge(Audit::query()->distinct()->pluck('auditable_type'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $types;
    }

    /**
     * One source, projected onto the shared nine columns.
     *
     * @param  array<string, mixed>  $filters
     */
    private function projection(string $source, array $filters): QueryBuilder
    {
        $query = match ($source) {
            self::SOURCE_TIMELINE => DB::table('activity_timelines')->select([
                DB::raw("'".self::SOURCE_TIMELINE."' as source"),
                'id as source_id',
                'uuid',
                'event',
                'description as summary',
                'actor_name',
                'subject_type',
                'subject_id',
                'occurred_at',
            ]),

            self::SOURCE_DATA => DB::table('audits')
                ->leftJoin('users', 'users.id', '=', 'audits.user_id')
                ->select([
                    DB::raw("'".self::SOURCE_DATA."' as source"),
                    'audits.id as source_id',
                    // `audits` has no UUID of its own. Left null rather than
                    // synthesised: a made-up identifier in an audit view is worse
                    // than an absent one.
                    DB::raw('null as uuid'),
                    'audits.event',
                    DB::raw('audits.auditable_type as summary'),
                    DB::raw('users.name as actor_name'),
                    DB::raw('audits.auditable_type as subject_type'),
                    DB::raw('audits.auditable_id as subject_id'),
                    DB::raw('audits.created_at as occurred_at'),
                ]),

            default => DB::table('workflow_transitions')
                ->leftJoin('users', 'users.id', '=', 'workflow_transitions.actor_id')
                ->select([
                    DB::raw("'".self::SOURCE_WORKFLOW."' as source"),
                    'workflow_transitions.id as source_id',
                    'workflow_transitions.uuid',
                    'workflow_transitions.event',
                    // The transition itself, as one legible string.
                    DB::raw($this->concat([
                        'workflow_transitions.workflow',
                        "': '",
                        "coalesce(workflow_transitions.from_state, '(none)')",
                        "' → '",
                        'workflow_transitions.to_state',
                    ]).' as summary'),
                    DB::raw('users.name as actor_name'),
                    'workflow_transitions.subject_type',
                    'workflow_transitions.subject_id',
                    'workflow_transitions.occurred_at',
                ]),
        };

        return $this->applyFilters($query, $source, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(QueryBuilder $query, string $source, array $filters): QueryBuilder
    {
        // Column names differ per source, so each filter names its own column
        // rather than assuming a shared one — a filter silently applied to the
        // wrong column would return a confident, wrong answer.
        [$eventCol, $subjectTypeCol, $subjectIdCol, $timeCol, $actorCol] = match ($source) {
            self::SOURCE_TIMELINE => ['event', 'subject_type', 'subject_id', 'occurred_at', 'actor_name'],
            self::SOURCE_DATA => ['audits.event', 'audits.auditable_type', 'audits.auditable_id', 'audits.created_at', 'users.name'],
            default => ['workflow_transitions.event', 'workflow_transitions.subject_type', 'workflow_transitions.subject_id', 'workflow_transitions.occurred_at', 'users.name'],
        };

        if (isset($filters['source_id'])) {
            $query->where($source === self::SOURCE_TIMELINE ? 'id' : 'source_id', (int) $filters['source_id']);
        }

        if (($event = trim((string) ($filters['event'] ?? ''))) !== '') {
            $query->where($eventCol, 'like', '%'.$event.'%');
        }

        if (($actor = trim((string) ($filters['actor'] ?? ''))) !== '') {
            if ($source === self::SOURCE_TIMELINE) {
                // The timeline carries an actor *snapshot*, which is the point of
                // it: it records who the actor was then, so it is searched
                // directly rather than through the users table.
                $query->where('actor_name', 'like', '%'.$actor.'%');
            } else {
                $query->where('users.name', 'like', '%'.$actor.'%');
            }
        }

        if (($type = trim((string) ($filters['subject_type'] ?? ''))) !== '') {
            $query->where($subjectTypeCol, $type);
        }

        if (($id = trim((string) ($filters['subject_id'] ?? ''))) !== '') {
            $query->where($subjectIdCol, (int) $id);
        }

        if (($from = trim((string) ($filters['from'] ?? ''))) !== '') {
            $query->whereDate($timeCol, '>=', $from);
        }

        if (($to = trim((string) ($filters['to'] ?? ''))) !== '') {
            $query->whereDate($timeCol, '<=', $to);
        }

        unset($actorCol);

        return $query;
    }

    /**
     * String concatenation that works on both engines.
     *
     * MySQL uses `concat()`; SQLite — which the test suite runs on — uses `||`.
     * Written once here rather than assumed, because the version that works only
     * on the engine the tests use is the version that ships broken (TD-24's
     * lesson, applied before it costs anything).
     *
     * @param  list<string>  $parts
     */
    private function concat(array $parts): string
    {
        $driver = DB::connection()->getDriverName();

        return $driver === 'sqlite'
            ? '('.implode(' || ', $parts).')'
            : 'concat('.implode(', ', $parts).')';
    }
}
