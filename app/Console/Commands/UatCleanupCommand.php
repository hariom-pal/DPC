<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Maintenance\UatCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Return a UAT database to "nothing has happened yet": every DPC record, file and
 * history gone, every master kept, and only the accounts of the preserved roles left.
 *
 * A dry run unless `--apply`. `--apply` is refused in production with no override,
 * allowed only in the environments of `dpc.uat_cleanup.allowed_environments`, and
 * only with the database named twice and a recent, complete dump of it.
 * See docs/UAT-DATA-CLEANUP.md.
 */
class UatCleanupCommand extends Command
{
    protected $signature = 'dpc:uat-cleanup
        {--apply : Delete. Without it the command only reports}
        {--database= : The connected database name, required with --apply}
        {--confirm= : The database name again, instead of typing it}
        {--backup= : A mysqldump of that database, under 24 hours old, required with --apply}
        {--report= : Write the plan and the result as JSON to this file}';

    protected $description = 'Clear all DPC data and every account except the preserved roles, keeping masters (UAT only)';

    private const BACKUP_MAX_AGE_SECONDS = 86400;

    public function handle(UatCleanupService $cleanup): int
    {
        if (app()->environment('production')) {
            $this->error('Refused: this command never runs in production.');

            return self::FAILURE;
        }

        $database = (string) DB::connection()->getDatabaseName();

        try {
            $plan = $cleanup->plan();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printPlan($plan);
        $report = ['generated_at' => now()->toIso8601String(), 'environment' => app()->environment(), 'plan' => $plan];

        if (! (bool) $this->option('apply')) {
            $this->writeReport($report);
            $this->info('Dry run: nothing was changed. Add --apply to delete.');

            return $plan['blockers'] === [] ? self::SUCCESS : self::FAILURE;
        }

        if (($refusal = $this->refusal($database, $plan['blockers'])) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $lock = fopen(storage_path('framework/cache/dpc-uat-cleanup.lock'), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Another cleanup is running.');

            return self::FAILURE;
        }

        try {
            $result = $cleanup->apply();
        } catch (Throwable $exception) {
            $this->error('Rolled back, nothing was deleted: '.$exception->getMessage());
            $this->writeReport([...$report, 'error' => $exception->getMessage()]);

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $after = $cleanup->plan();
        $this->writeReport([...$report, 'result' => $result, 'after' => $after], required: true);

        $this->info(sprintf(
            'Done: %d row(s) deleted, %d reference(s) cleared, %d file(s) deleted (%d shared kept, %d already missing). %d account(s) remain.',
            array_sum($result['deleted']),
            $result['cleared_references'],
            $result['files']['deleted'],
            $result['files']['kept_shared'],
            $result['files']['missing'],
            count($after['kept_users']),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $blockers
     */
    private function refusal(string $database, array $blockers): ?string
    {
        $allowed = (array) config('dpc.uat_cleanup.allowed_environments', ['uat']);

        if (! in_array(app()->environment(), $allowed, true)) {
            return 'Refused: --apply is allowed only in '.implode(', ', $allowed).' (this is '.app()->environment().').';
        }

        if ($blockers !== []) {
            return 'Refused: the pre-flight found blockers.';
        }

        if ((string) $this->option('database') !== $database) {
            return "Refused: --database must name the connected database ({$database}).";
        }

        $confirm = $this->option('confirm') !== null
            ? (string) $this->option('confirm')
            : (string) $this->ask("Type the database name ({$database}) to delete its data");

        if ($confirm !== $database) {
            return 'Refused: the confirmation did not match the database name.';
        }

        return $this->backupProblem((string) $this->option('backup'), $database);
    }

    private function backupProblem(string $path, string $database): ?string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return 'Refused: --backup must name a readable dump file.';
        }

        if (time() - (int) filemtime($path) > self::BACKUP_MAX_AGE_SECONDS) {
            return 'Refused: the backup is older than 24 hours.';
        }

        $gzip = str_ends_with($path, '.gz');
        $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');

        if ($handle === false) {
            return 'Refused: the backup cannot be opened.';
        }

        $namesDatabase = false;
        $hasUsers = false;
        $last = '';
        $lineNo = 0;

        while (($line = ($gzip ? gzgets($handle) : fgets($handle))) !== false) {
            $lineNo++;

            if ($lineNo <= 20 && preg_match('/^-- Host: .*Database: (\S+)/', $line, $m) === 1) {
                $namesDatabase = $m[1] === $database;
            }

            if (! $hasUsers && str_starts_with($line, 'CREATE TABLE `users`')) {
                $hasUsers = true;
            }

            if (trim($line) !== '') {
                $last = $line;
            }
        }

        $gzip ? gzclose($handle) : fclose($handle);

        return match (false) {
            $namesDatabase => "Refused: the backup's header does not name {$database}.",
            $hasUsers => 'Refused: the backup has no users table.',
            str_starts_with($last, '-- Dump completed') => 'Refused: the backup is incomplete (no "-- Dump completed").',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function printPlan(array $plan): void
    {
        $this->line("Database: {$plan['database']}    Preserved roles: ".implode(', ', $plan['preserve_roles']));

        $this->table(['Table', 'Class', 'Rows', 'To delete'], array_map(
            fn (array $row): array => [$row['table'], $row['class'], $row['rows'], $row['delete']],
            array_values(array_filter($plan['tables'], fn (array $row): bool => $row['rows'] > 0 || $row['class'] !== 'preserve')),
        ));

        $this->line('Accounts kept:');

        foreach ($plan['kept_users'] as $user) {
            $this->line("  #{$user['id']} {$user['name']} (".implode(', ', $user['roles']).')');
        }

        $this->line("Accounts deleted: {$plan['deleted_users']}    Files to delete: {$plan['files']}");

        foreach ($plan['set_null'] as $effect) {
            $this->warn("  SET NULL: {$effect['table']}.{$effect['column']} on {$effect['rows']} surviving row(s) → {$effect['references']}");
        }

        foreach ($plan['unknown'] as $table) {
            $this->warn("  Not classified, preserved: {$table}");
        }

        foreach ($plan['blockers'] as $blocker) {
            $this->error("  BLOCKER: {$blocker}");
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(array $report, bool $required = false): void
    {
        $path = $this->option('report');

        if (($path === null || $path === '') && $required) {
            $path = storage_path('logs/uat-cleanup-'.now()->format('Ymd-His').'.json');
        }

        if ($path === null || $path === '') {
            return;
        }

        file_put_contents((string) $path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line("Report: {$path}");
    }
}
