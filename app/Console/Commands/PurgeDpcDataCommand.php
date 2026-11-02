<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Maintenance\DpcDataPurgeService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Clear every Pre-DPC and DPC record, keeping every account and master (2026-11-02).
 * The same purge as Admin → Clear DPC data; what goes is `DpcDataPurgePlan`.
 *
 * A dry run unless `--apply`; `--apply` asks for the database name (or takes it from
 * `--confirm`). Refused in production, and outside `dpc.dpc_data_purge.allowed_environments`.
 */
class PurgeDpcDataCommand extends Command
{
    protected $signature = 'dpc:purge-dpc-data
        {--apply : Delete. Without it the command only reports}
        {--confirm= : The database name, instead of typing it}';

    protected $description = 'Clear all Pre-DPC and DPC data, keeping every account and master';

    public function handle(DpcDataPurgeService $purge): int
    {
        $plan = $purge->plan();

        $this->line("Database: {$plan['database']}    Environment: {$plan['environment']}");
        $this->table(['Table', 'Holds', 'Rows'], array_map(
            fn (array $row): array => [$row['table'], $row['what'], $row['rows']],
            $plan['tables'],
        ));
        $this->table(['Shared table', 'Pre-DPC / DPC rows'], array_map(
            fn (array $row): array => [$row['table'], $row['rows']],
            $plan['shared'],
        ));
        $this->line("Rows to delete: {$plan['total']}    Files to delete: {$plan['files']}");

        foreach ($plan['set_null'] as $effect) {
            $this->warn("  SET NULL: {$effect['table']}.{$effect['column']} on {$effect['rows']} row(s) that stay → {$effect['references']}");
        }

        foreach ($plan['blockers'] as $blocker) {
            $this->error("  BLOCKER: {$blocker}");
        }

        if (! (bool) $this->option('apply')) {
            $this->info('Dry run: nothing was changed. Add --apply to delete.');

            return $plan['blockers'] === [] ? self::SUCCESS : self::FAILURE;
        }

        if (! $plan['allowed']) {
            $this->error("Refused: not allowed in the {$plan['environment']} environment.");

            return self::FAILURE;
        }

        $confirm = $this->option('confirm') !== null
            ? (string) $this->option('confirm')
            : (string) $this->ask("Type the database name ({$plan['database']}) to delete its Pre-DPC and DPC data");

        try {
            $result = $purge->apply($confirm);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Done: %d row(s) deleted, %d file(s) deleted (%d shared kept, %d already missing). Accounts and masters unchanged.',
            $result['total'],
            $result['files']['deleted'],
            $result['files']['kept_shared'],
            $result['files']['missing'],
        ));

        return self::SUCCESS;
    }
}
