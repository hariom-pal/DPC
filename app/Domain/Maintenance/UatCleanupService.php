<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The work behind `dpc:uat-cleanup`: return a database to "nothing has happened yet",
 * keeping the accounts of the preserved roles and every master the application needs.
 *
 * What is emptied, trimmed and kept is {@see UatCleanupPlan}. This class decides the
 * ORDER from the schema's own foreign keys, checks every foreign key from a surviving
 * row into a deleted one before anything is written, deletes in one transaction with
 * foreign-key checks ON, and removes files only after the commit, only when no
 * remaining row still names them.
 */
final class UatCleanupService
{
    public function __construct(private readonly ForeignKeyGraph $graph = new ForeignKeyGraph) {}

    /**
     * Everything the cleanup would do, without doing it.
     *
     *
     * @param  list<string>|null  $preserveRoles  the roles whose accounts stay; the configured ones when null
     * @return array{
     *   database: string,
     *   preserve_roles: list<string>,
     *   kept_users: list<array{id: int, name: string, roles: list<string>}>,
     *   deleted_users: int,
     *   tables: list<array{table: string, class: string, reason: string, rows: int, delete: int}>,
     *   unknown: list<string>,
     *   set_null: list<array{table: string, column: string, references: string, rows: int}>,
     *   blockers: list<string>,
     *   files: int
     * }
     */
    public function plan(?array $preserveRoles = null): array
    {
        $tables = $this->tables();
        $keep = $this->keptUserIds($preserveRoles);
        $deletedUsers = DB::table('users')->whereNotIn('id', $keep)->count();

        $rows = [];
        $unknown = [];

        foreach ($tables as $table) {
            $class = UatCleanupPlan::classify($table);
            $count = DB::table($table)->count();

            if ($class === 'unknown') {
                $unknown[] = $table;
            }

            $rows[] = [
                'table' => $table,
                'class' => $class,
                'reason' => UatCleanupPlan::reason($table),
                'rows' => $count,
                'delete' => match ($class) {
                    'clean' => $count,
                    'partial' => $this->partialQuery($table, $keep)?->count() ?? 0,
                    default => 0,
                },
            ];
        }

        [$setNull, $blockers] = $this->preflight($tables, $keep);

        return [
            'database' => DB::connection()->getDatabaseName(),
            'preserve_roles' => $this->preserveRoles($preserveRoles),
            'kept_users' => User::query()->whereIn('id', $keep)->orderBy('id')->get()
                ->map(fn (User $user): array => [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'roles' => $user->getRoleNames()->values()->all(),
                ])->all(),
            'deleted_users' => $deletedUsers,
            'tables' => $rows,
            'unknown' => $unknown,
            'set_null' => $setNull,
            'blockers' => $blockers,
            'files' => count($this->collectFiles($tables)),
        ];
    }

    /**
     * Do it: one transaction, foreign-key checks on, files after the commit.
     *
     * @param  list<string>|null  $preserveRoles
     * @return array{deleted: array<string, int>, cleared_references: int, files: array{deleted: int, kept_shared: int, missing: int}}
     */
    public function apply(?array $preserveRoles = null): array
    {
        $tables = $this->tables();
        $keep = $this->keptUserIds($preserveRoles);
        [, $blockers] = $this->preflight($tables, $keep);

        if ($blockers !== []) {
            throw new RuntimeException('Blocked: '.implode(' ', $blockers));
        }

        $files = $this->collectFiles($tables);
        $deleted = [];
        $cleared = 0;

        DB::transaction(function () use ($tables, $keep, &$deleted, &$cleared): void {
            $clean = array_values(array_filter($tables, fn (string $t): bool => UatCleanupPlan::classify($t) === 'clean'));

            // 1. Break cycles: nullable references between two emptied tables go to NULL.
            $cleared = $this->graph->breakCycles($clean);

            // 2. Child before parent, from the remaining (non-null) references.
            foreach ($this->graph->deleteOrder($clean) as $table) {
                $deleted[$table] = DB::table($table)->delete();
            }

            // 3. The accounts that are not kept, after their pivot rows.
            foreach (['model_has_roles', 'model_has_permissions', 'user_department_assignments', 'users'] as $table) {
                if (in_array($table, $tables, true)) {
                    $deleted[$table] = (int) $this->partialQuery($table, $keep)?->delete();
                }
            }
        });

        return ['deleted' => $deleted, 'cleared_references' => $cleared, 'files' => $this->graph->deleteFiles($files, UatCleanupPlan::FILE_REFERENCES)];
    }

    /**
     * The users who survive: holders of a preserved role. At least one CGPSC
     * Administrator must be among them, or the portal could not be administered.
     *
     * @param  list<string>|null  $preserveRoles
     * @return list<int>
     */
    public function keptUserIds(?array $preserveRoles = null): array
    {
        $roles = $this->preserveRoles($preserveRoles);

        $ids = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('roles.name', $roles)
            ->distinct()
            ->pluck('model_has_roles.model_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $admins = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('roles.name', 'admin')
            ->count();

        if ($admins === 0) {
            throw new RuntimeException('No account holds the admin role, so the cleanup refuses to run.');
        }

        sort($ids);

        return $ids;
    }

    /**
     * Clear DPC data's "delete the users too" keeps the Administrators only (2026-11-03).
     *
     * @param  list<string>|null  $override
     * @return list<string>
     */
    public function preserveRoles(?array $override = null): array
    {
        $roles = array_values(array_filter(array_map('trim', $override ?? (array) config('dpc.uat_cleanup.preserve_roles', ['admin', 'chairman']))));

        return in_array('admin', $roles, true) ? $roles : ['admin', ...$roles];
    }

    // ---------------------------------------------------------------- internals

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_values(Schema::getTableListing(Schema::getCurrentSchemaListing(), false));
    }

    /**
     * Rows of a trimmed table that belong to accounts not kept.
     *
     * @param  list<int>  $keep
     */
    private function partialQuery(string $table, array $keep): ?Builder
    {
        return match ($table) {
            'users' => DB::table('users')->whereNotIn('id', $keep),
            'model_has_roles', 'model_has_permissions' => DB::table($table)
                ->where('model_type', (new User)->getMorphClass())
                ->whereNotIn('model_id', $keep),
            'user_department_assignments' => DB::table($table)->whereNotIn('user_id', $keep),
            default => null,
        };
    }

    /**
     * The rows of a table that remain after the cleanup (all of them, for a preserved table).
     *
     * @param  list<int>  $keep
     */
    private function survivors(string $table, array $keep): Builder
    {
        $morph = (new User)->getMorphClass();

        return match ($table) {
            'users' => DB::table('users')->whereIn('id', $keep),
            'model_has_roles', 'model_has_permissions' => DB::table($table)->where(
                fn ($q) => $q->where('model_type', '!=', $morph)->orWhereIn('model_id', $keep),
            ),
            'user_department_assignments' => DB::table($table)->whereIn('user_id', $keep),
            default => DB::table($table),
        };
    }

    /**
     * Every foreign key from a SURVIVING row into a row that will be deleted.
     *
     * SET NULL is reported (the surviving row loses its link); CASCADE, RESTRICT or NO
     * ACTION with a matching row is a blocker, because it would either delete
     * something meant to survive or make the transaction fail half way.
     *
     * @param  list<string>  $tables
     * @param  list<int>  $keep
     * @return array{0: list<array{table: string, column: string, references: string, rows: int}>, 1: list<string>}
     */
    private function preflight(array $tables, array $keep): array
    {
        $setNull = [];
        $blockers = [];

        foreach ($tables as $table) {
            $class = UatCleanupPlan::classify($table);

            if ($class === 'clean') {
                continue;   // its own rows go too
            }

            foreach ($this->graph->foreignKeys($table) as $fk) {
                $target = $fk['foreign_table'];
                $column = $fk['columns'][0];
                $query = $this->survivors($table, $keep)->whereNotNull($column);

                if (UatCleanupPlan::classify($target) === 'clean') {
                    $rows = $query->count();
                } elseif ($target === 'users') {
                    $rows = $query->whereNotIn($column, $keep)->count();
                } else {
                    continue;
                }

                if ($rows === 0) {
                    continue;
                }

                if (strtolower((string) $fk['on_delete']) === 'set null') {
                    $setNull[] = ['table' => $table, 'column' => $column, 'references' => $target, 'rows' => $rows];
                } else {
                    $blockers[] = "{$table}.{$column} ({$rows} surviving row(s)) references {$target} with ON DELETE {$fk['on_delete']}.";
                }
            }
        }

        return [$setNull, $blockers];
    }

    /**
     * Files named by rows that will be emptied.
     *
     * @param  list<string>  $tables
     * @return list<array{disk: string, path: string}>
     */
    private function collectFiles(array $tables): array
    {
        $files = [];

        foreach (UatCleanupPlan::FILE_REFERENCES as $table => $pairs) {
            if (UatCleanupPlan::classify($table) !== 'clean' || ! in_array($table, $tables, true)) {
                continue;
            }

            $files = [...$files, ...$this->graph->filesOf(DB::table($table), $pairs)];
        }

        return array_values(array_unique($files, SORT_REGULAR));
    }
}
