<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\SecurityLog;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The work behind `dpc:purge-dpc-data` and Admin → Clear DPC data: every Pre-DPC and
 * DPC record gone, everything else kept. What goes is {@see DpcDataPurgePlan}.
 *
 * One transaction with foreign-key checks ON, child before parent from the schema's
 * own keys; a reference from a row that stays into a row that goes is checked first
 * (SET NULL is reported, anything else blocks). Files go after the commit, only when
 * no remaining row names them.
 *
 * Never in production, whatever the configuration says; elsewhere only in the
 * environments of `dpc.dpc_data_purge.allowed_environments`.
 *
 * **Users too, when asked** (2026-11-03: "sirf admin rakhna hai active mode pe baaki
 * sab delete hona hai"): every account except the Administrators goes, with what
 * only an account owns (postings, sessions, OTPs, identity records, invitations,
 * the security log, audits, timelines), through `UatCleanupService` keeping the
 * `admin` role only. The Administrators are left active. Masters stay.
 */
final class DpcDataPurgeService
{
    /** The only role whose accounts survive "delete the users too". */
    public const KEPT_ROLES = [Role::Admin->value];

    public function __construct(
        private readonly SecurityLog $securityLog,
        private readonly UatCleanupService $users,
        private readonly ForeignKeyGraph $graph = new ForeignKeyGraph,
    ) {}

    /**
     * What "delete the users too" would do: the accounts that go, the ones kept,
     * and anything that stops it.
     *
     * @return array{delete: int, kept: list<array{id: int, name: string, roles: list<string>}>, blockers: list<string>}
     */
    public function usersPlan(): array
    {
        try {
            $plan = $this->users->plan(self::KEPT_ROLES);
        } catch (RuntimeException $e) {
            return ['delete' => 0, 'kept' => [], 'blockers' => [$e->getMessage()]];
        }

        return ['delete' => $plan['deleted_users'], 'kept' => $plan['kept_users'], 'blockers' => $plan['blockers']];
    }

    public function allowed(): bool
    {
        return ! app()->environment('production')
            && in_array(app()->environment(), (array) config('dpc.dpc_data_purge.allowed_environments', []), true);
    }

    public function database(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    /**
     * Everything the purge would do, without doing it.
     *
     * @return array{
     *   database: string,
     *   environment: string,
     *   allowed: bool,
     *   tables: list<array{table: string, what: string, rows: int}>,
     *   shared: list<array{table: string, rows: int}>,
     *   set_null: list<array{table: string, column: string, references: string, rows: int}>,
     *   blockers: list<string>,
     *   total: int,
     *   files: int
     * }
     */
    public function plan(): array
    {
        $types = $this->purgedTypes();
        $tables = [];
        $shared = [];

        foreach ($this->emptied() as $table) {
            $tables[] = ['table' => $table, 'what' => DpcDataPurgePlan::TABLES[$table], 'rows' => DB::table($table)->count()];
        }

        foreach ($this->sharedTables() as $table) {
            $shared[] = ['table' => $table, 'rows' => $this->sharedRows($table, $types)->count()];
        }

        [$setNull, $blockers] = $this->preflight($types);

        return [
            'database' => $this->database(),
            'environment' => (string) app()->environment(),
            'allowed' => $this->allowed(),
            'tables' => $tables,
            'shared' => $shared,
            'set_null' => $setNull,
            'blockers' => $blockers,
            'total' => array_sum(array_column($tables, 'rows')) + array_sum(array_column($shared, 'rows')),
            'files' => count($this->collectFiles($types)),
        ];
    }

    /**
     * Do it. `$confirmation` must be the connected database's name.
     *
     * @return array{deleted: array<string, int>, total: int, cleared_references: int, files: array{deleted: int, kept_shared: int, missing: int}, users: int}
     */
    public function apply(string $confirmation, ?User $actor = null, bool $withUsers = false): array
    {
        if (! $this->allowed()) {
            throw new RuntimeException('Refused: clearing DPC data is not allowed in the '.app()->environment().' environment.');
        }

        if ($confirmation !== $this->database()) {
            throw new RuntimeException("Refused: the confirmation did not match the database name ({$this->database()}).");
        }

        $lock = fopen(storage_path('framework/cache/dpc-data-purge.lock'), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Refused: another purge is running.');
        }

        try {
            // Checked before anything goes, so a blocked user cleanup never leaves
            // the DPC data half-cleared behind it.
            if ($withUsers && ($blockers = $this->usersPlan()['blockers']) !== []) {
                throw new RuntimeException('Blocked: '.implode(' ', $blockers));
            }

            $result = $this->purge() + ['users' => 0];

            if ($withUsers) {
                $removed = $this->users->apply(self::KEPT_ROLES);
                $result['users'] = (int) ($removed['deleted']['users'] ?? 0);
                $result['total'] += array_sum($removed['deleted']);
                $result['files']['deleted'] += $removed['files']['deleted'];

                // The Administrators are left able to sign in.
                User::role(self::KEPT_ROLES)->update(['is_active' => true]);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        // The landing page's counts would otherwise still show the cleared DPCs.
        Cache::forget('portal.statistics');

        $this->securityLog->record('dpc_data.purged', $actor, null, [
            'database' => $this->database(),
            'rows' => $result['total'],
            'files' => $result['files']['deleted'],
            'users' => $result['users'],
        ], $actor);

        return $result;
    }

    /**
     * @return array{deleted: array<string, int>, total: int, cleared_references: int, files: array{deleted: int, kept_shared: int, missing: int}}
     */
    private function purge(): array
    {
        $types = $this->purgedTypes();
        [, $blockers] = $this->preflight($types);

        if ($blockers !== []) {
            throw new RuntimeException('Blocked: '.implode(' ', $blockers));
        }

        $files = $this->collectFiles($types);
        $deleted = [];
        $cleared = 0;

        DB::transaction(function () use ($types, &$deleted, &$cleared): void {
            $emptied = $this->emptied();
            $cleared = $this->graph->breakCycles($emptied);

            foreach ($this->graph->deleteOrder([...$emptied, ...$this->sharedTables()]) as $table) {
                $deleted[$table] = array_key_exists($table, DpcDataPurgePlan::SHARED)
                    ? $this->sharedRows($table, $types)->delete()
                    : DB::table($table)->delete();
            }
        });

        return [
            'deleted' => array_filter($deleted),
            'total' => array_sum($deleted),
            'cleared_references' => $cleared,
            'files' => $this->graph->deleteFiles($files, DpcDataPurgePlan::FILE_REFERENCES),
        ];
    }

    /**
     * The purged tables that exist in this schema.
     *
     * @return list<string>
     */
    private function emptied(): array
    {
        return array_values(array_filter(array_keys(DpcDataPurgePlan::TABLES), fn (string $t): bool => Schema::hasTable($t)));
    }

    /**
     * @return list<string>
     */
    private function sharedTables(): array
    {
        return array_values(array_filter(array_keys(DpcDataPurgePlan::SHARED), fn (string $t): bool => Schema::hasTable($t)));
    }

    /**
     * The morph types, as stored, whose model lives in a purged table. Read off the
     * shared tables themselves, so a DPC model added later is caught without a list.
     *
     * @return list<string>
     */
    private function purgedTypes(): array
    {
        $types = [];

        foreach ($this->sharedTables() as $table) {
            foreach (DB::table($table)->distinct()->pluck(DpcDataPurgePlan::SHARED[$table]) as $type) {
                $class = Relation::getMorphedModel((string) $type) ?? (string) $type;

                if (class_exists($class) && is_subclass_of($class, Model::class)
                    && array_key_exists((new $class)->getTable(), DpcDataPurgePlan::TABLES)) {
                    $types[] = (string) $type;
                }
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  list<string>  $types
     */
    private function sharedRows(string $table, array $types): Builder
    {
        return DB::table($table)->whereIn(DpcDataPurgePlan::SHARED[$table], $types);
    }

    /**
     * Every foreign key from a row that STAYS into a row that goes.
     *
     * @param  list<string>  $types
     * @return array{0: list<array{table: string, column: string, references: string, rows: int}>, 1: list<string>}
     */
    private function preflight(array $types): array
    {
        $setNull = [];
        $blockers = [];
        $emptied = $this->emptied();

        foreach (Schema::getTableListing(Schema::getCurrentSchemaListing(), false) as $table) {
            if (in_array($table, $emptied, true)) {
                continue;   // its own rows go too
            }

            foreach ($this->graph->foreignKeys($table) as $fk) {
                $target = $fk['foreign_table'];
                $column = $fk['columns'][0];
                $staying = array_key_exists($table, DpcDataPurgePlan::SHARED)
                    ? DB::table($table)->whereNotIn(DpcDataPurgePlan::SHARED[$table], $types)
                    : DB::table($table);
                $staying->whereNotNull($column);

                if (in_array($target, $emptied, true)) {
                    $rows = $staying->count();
                } elseif (array_key_exists($target, DpcDataPurgePlan::SHARED)) {
                    $rows = $staying->whereIn($column, $this->sharedRows($target, $types)->select($fk['foreign_columns'][0]))->count();
                } else {
                    continue;
                }

                if ($rows === 0) {
                    continue;
                }

                if (strtolower((string) $fk['on_delete']) === 'set null') {
                    $setNull[] = ['table' => $table, 'column' => $column, 'references' => $target, 'rows' => $rows];
                } else {
                    $blockers[] = "{$table}.{$column} ({$rows} row(s) that stay) references {$target} with ON DELETE {$fk['on_delete']}.";
                }
            }
        }

        return [$setNull, $blockers];
    }

    /**
     * Files named by the rows that go.
     *
     * @param  list<string>  $types
     * @return list<array{disk: string, path: string}>
     */
    private function collectFiles(array $types): array
    {
        $files = [];

        foreach (DpcDataPurgePlan::FILE_REFERENCES as $table => $pairs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (array_key_exists($table, DpcDataPurgePlan::TABLES)) {
                $files = [...$files, ...$this->graph->filesOf(DB::table($table), $pairs)];
            } elseif (array_key_exists($table, DpcDataPurgePlan::SHARED)) {
                $files = [...$files, ...$this->graph->filesOf($this->sharedRows($table, $types), $pairs)];
            }
        }

        return array_values(array_unique($files, SORT_REGULAR));
    }
}
