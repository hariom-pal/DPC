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
 *
 * `--with-users` (2026-11-03) also deletes every account except the Administrators,
 * and leaves the Administrators active.
 */
class PurgeDpcDataCommand extends Command
{
    protected $signature = 'dpc:purge-dpc-data
        {--apply : Delete. Without it the command only reports}
        {--confirm= : The database name, instead of typing it}
        {--with-users : Also delete every user account except the Administrators (who are left active)}';

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

        $withUsers = (bool) $this->option('with-users');

        if ($withUsers) {
            $users = $purge->usersPlan();
            $this->line(sprintf('Accounts to delete: %d    Kept (Administrators, left active): %s',
                $users['delete'],
                implode(', ', array_map(fn (array $user): string => "#{$user['id']} {$user['name']}", $users['kept'])) ?: 'none'));

            foreach ($users['blockers'] as $blocker) {
                $this->error("  BLOCKER (users): {$blocker}");
            }

            $plan['blockers'] = [...$plan['blockers'], ...$users['blockers']];
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
            $result = $purge->apply($confirm, null, $withUsers);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Done: %d row(s) deleted, %d file(s) deleted (%d shared kept, %d already missing). %s',
            $result['total'],
            $result['files']['deleted'],
            $result['files']['kept_shared'],
            $result['files']['missing'],
            $withUsers ? "{$result['users']} account(s) deleted; the Administrators are active. Masters unchanged." : 'Accounts and masters unchanged.',
        ));

        return self::SUCCESS;
    }
}
