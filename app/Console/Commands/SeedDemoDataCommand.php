<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Review\Models\DpcReviewLink;
use Database\Seeders\Demo\DemoAccountSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Rebuild the demonstration database in one step.
 *
 * The demo data is a sequence of workflow events, not a set of rows, so it is
 * built on a clean schema rather than merged into whatever is already there —
 * that is what `--fresh` is for, and it is the default. The command finishes by
 * printing the credentials and identifiers the presenter actually needs, so the
 * cheat sheet is never out of step with the database.
 */
class SeedDemoDataCommand extends Command
{
    protected $signature = 'demo:seed
        {--keep : Seed on top of the existing database instead of rebuilding it}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Rebuild the demonstration database (departments, officers, masters and DPCs) and print the demo cheat sheet';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('demo:seed creates accounts with a shared, published password and is refused in production.');

            return self::FAILURE;
        }

        $fresh = ! $this->option('keep');

        if ($fresh && ! $this->option('force') && ! $this->confirm(
            'This will DROP every table in ['.config('database.connections.'.config('database.default').'.database')
            .'] and rebuild it with demo data. Continue?',
        )) {
            $this->comment('Aborted. Nothing was changed.');

            return self::SUCCESS;
        }

        if ($fresh) {
            $this->call('migrate:fresh', ['--force' => true]);
        }

        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $this->cheatSheet();

        return self::SUCCESS;
    }

    private function cheatSheet(): void
    {
        $this->newLine();
        $this->info('e-DPC demo data ready. Full walkthrough: docs/DEMO_CHEAT_SHEET.md');
        $this->newLine();

        $this->line('  <options=bold>Password for every demo account:</> '.DemoAccountSeeder::PASSWORD);
        $this->newLine();

        $this->table(
            ['Role', 'Login (email or mobile)', 'Name'],
            $this->accounts(),
        );

        $this->table(['Record', 'Identifier'], $this->identifiers());
    }

    /**
     * @return list<array{0:string, 1:string, 2:string}>
     */
    private function accounts(): array
    {
        $rows = [];

        $departmentCodes = Department::query()->pluck('code', 'id');

        foreach (User::query()->with('roles')->orderBy('id')->get() as $user) {
            $role = $user->getRoleNames()->first() ?? '—';
            $department = $departmentCodes[$user->department_id] ?? null;

            $rows[] = [
                $role.($department === null ? '' : " ({$department})"),
                $user->email.' / '.$user->mobile,
                $user->name,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{0:string, 1:string}>
     */
    private function identifiers(): array
    {
        $department = Department::query()->where('code', 'SE')->first();

        if ($department === null) {
            throw new RuntimeException('The School Education Department was not seeded.');
        }

        $reviewIds = DpcReviewLink::query()->pluck('review_dpc_proposal_id')->all();

        $rows = [];

        foreach (DpcProposal::query()
            ->where('department_id', $department->id)
            ->whereNotNull('dpc_code')
            ->orderBy('id')
            ->get() as $proposal) {
            $label = in_array($proposal->id, $reviewIds, true) ? 'Review DPC' : 'DPC';

            $rows[] = [
                $label.' — '.$proposal->postToCadre->name,
                (string) $proposal->dpc_code,
            ];
        }

        foreach (DpcCirculation::query()
            ->where('department_id', $department->id)
            ->orderBy('id')
            ->get() as $circulation) {
            $rows[] = ['Circulation DPC', (string) ($circulation->circulation_code ?? $circulation->uuid)];
        }

        return $rows;
    }
}
