<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Build the whole schema on MySQL, in a throwaway database, and drop it.
 *
 * **TD-24 step 2. This exists because its absence cost a P0.**
 *
 * The test suite runs on in-memory SQLite; the application runs on MySQL. A
 * migration whose derived foreign-key name came out at 65 characters passed
 * **84 green tests** and could not be created by `migrate:fresh` at all —
 * MySQL refuses any identifier over 64 characters (error 1059) and SQLite has
 * no such limit. `dpc_document_review_references` had never existed in the
 * development database, and four documents recorded the gate as green on a
 * table count taken before the failure.
 *
 * `SchemaIdentifierLengthTest` (step 1) catches that one difference on any
 * engine. This catches **the rest of them** — column types MySQL rejects, index
 * limits, `strict` mode, defaults on `TEXT`, anything a migration can express
 * that only one of the two engines will accept. It does not enumerate those
 * differences; it lets MySQL do the enumerating, which is the only way to cover
 * the ones nobody has thought of yet.
 *
 * **It never touches the development database.** A scratch database is created,
 * migrated, counted and dropped. The developer's `dpc_dev` — with its
 * `demo:seed` ladder — is left exactly as it was, so this is safe to put in
 * `composer gate` where `migrate:fresh` never could be.
 *
 * **It fails rather than skipping.** A check that quietly passes when the engine
 * it exists to exercise is unavailable reports precisely the false green that
 * TD-24 is about. If MySQL is unreachable, the gate stops and says so.
 */
class VerifyMysqlSchemaCommand extends Command
{
    protected $signature = 'schema:verify-mysql
        {--connection=mysql : The configured connection whose server to build against}
        {--keep : Leave the scratch database in place for inspection}';

    protected $description = 'Build every migration against MySQL in a throwaway database (TD-24), then drop it';

    /**
     * The runtime connection this command builds on. Distinct from the
     * configured one so the scratch database name cannot be confused with the
     * real one at any point.
     */
    private const SCRATCH_CONNECTION = 'mysql_schema_verify';

    public function handle(): int
    {
        $source = (string) $this->option('connection');

        /** @var array<string, mixed>|null $config */
        $config = Config::get("database.connections.{$source}");

        if ($config === null) {
            $this->error("No connection named `{$source}` is configured.");

            return self::FAILURE;
        }

        $driver = (string) ($config['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error("Connection `{$source}` uses the `{$driver}` driver, not MySQL.");
            $this->line('This check exists precisely because the suite runs on a different engine from');
            $this->line('the application. Pointing it at that same engine would defeat it.');

            return self::FAILURE;
        }

        $scratch = $this->scratchDatabaseName((string) ($config['database'] ?? 'dpc'));

        // A connection to the *server* with no database selected, so the scratch
        // database can be created before anything connects to it.
        Config::set('database.connections.'.self::SCRATCH_CONNECTION, [...$config, 'database' => null]);
        DB::purge(self::SCRATCH_CONNECTION);

        try {
            $server = $this->serverVersion();
        } catch (Throwable $e) {
            $this->error('MySQL is unreachable, so the schema cannot be verified against it.');
            $this->line($e->getMessage());
            $this->newLine();
            $this->line('This is a failure, not a skip: a schema check that passes without running');
            $this->line('is the false green TD-24 exists to prevent.');

            return self::FAILURE;
        }

        $this->line("MySQL {$server} · scratch database <options=bold>{$scratch}</>");

        try {
            $this->createScratchDatabase($scratch);
        } catch (Throwable $e) {
            $this->error("Could not create the scratch database `{$scratch}`.");
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        try {
            return $this->migrateAndReport($scratch);
        } finally {
            if ($this->option('keep')) {
                $this->comment("Scratch database `{$scratch}` kept (--keep).");
            } else {
                $this->dropScratchDatabase($scratch);
            }
        }
    }

    /**
     * Run every migration and report what MySQL actually built.
     */
    private function migrateAndReport(string $scratch): int
    {
        Config::set('database.connections.'.self::SCRATCH_CONNECTION.'.database', $scratch);
        DB::purge(self::SCRATCH_CONNECTION);

        try {
            $exit = $this->call('migrate', [
                '--database' => self::SCRATCH_CONNECTION,
                '--force' => true,
                // Not `--no-interaction`: `migrate` on an empty database asks
                // nothing, and swallowing a prompt would hide a real question.
            ]);
        } catch (Throwable $e) {
            // `migrate` throws on a rejected statement rather than returning a
            // non-zero code, and an unhandled trace is the wrong output for the
            // one failure this command exists to report. The MySQL error is what
            // names the fault — error 1059 for the M8 case — so it is shown, and
            // shown before the trace scrolls it away.
            $this->newLine();
            $this->error('MySQL refused a migration. It may still pass on SQLite — that is the point of this check.');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        if ($exit !== self::SUCCESS) {
            $this->newLine();
            $this->error('Migrations failed on MySQL. They may still pass on SQLite — that is the point of this check.');

            return self::FAILURE;
        }

        // Scoped to the scratch schema deliberately. `getTableListing()` without
        // one reports every table on the server — 673 of them here on the first
        // run of this command — which would have made the figure meaningless
        // precisely where the M8 P0 was a table count nobody could reproduce.
        $tables = count(Schema::connection(self::SCRATCH_CONNECTION)->getTableListing($scratch));
        $migrations = (int) DB::connection(self::SCRATCH_CONNECTION)->table('migrations')->count();

        $this->newLine();
        $this->info("MySQL built the full schema: {$migrations} migrations, {$tables} tables.");

        return self::SUCCESS;
    }

    private function serverVersion(): string
    {
        /** @var object{version: string} $row */
        $row = DB::connection(self::SCRATCH_CONNECTION)->selectOne('select version() as version');

        return $row->version;
    }

    /**
     * A name derived from the real database, so it is obvious in a `SHOW
     * DATABASES` listing where it came from and that it is not production data.
     */
    private function scratchDatabaseName(string $database): string
    {
        // MySQL's 64-character limit applies to database names too, which would
        // be a poor place for this command to fall over.
        return substr(preg_replace('/[^A-Za-z0-9_]/', '_', $database) ?? 'dpc', 0, 45).'_schema_verify';
    }

    private function createScratchDatabase(string $scratch): void
    {
        $connection = DB::connection(self::SCRATCH_CONNECTION);

        // Dropped first: a previous run killed mid-migration leaves a partial
        // database behind, and migrating into that would report a green build
        // of a schema that was already there.
        $connection->statement("drop database if exists `{$scratch}`");
        $connection->statement("create database `{$scratch}` character set utf8mb4 collate utf8mb4_unicode_ci");
    }

    private function dropScratchDatabase(string $scratch): void
    {
        try {
            // Back to the server-level connection: MySQL will not drop the
            // database the current session is using.
            Config::set('database.connections.'.self::SCRATCH_CONNECTION.'.database', null);
            DB::purge(self::SCRATCH_CONNECTION);
            DB::connection(self::SCRATCH_CONNECTION)->statement("drop database if exists `{$scratch}`");
        } catch (Throwable $e) {
            // Reported, never fatal: the schema verdict is the command's result,
            // and a leftover scratch database is a housekeeping problem that the
            // next run's `drop database if exists` clears anyway.
            $this->warn("Could not drop the scratch database `{$scratch}`: {$e->getMessage()}");
        }
    }
}
