<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\SelfInspection\Enums\SelfInspectionFactor;
use App\Domain\SelfInspection\Services\SelfInspectionAccessGuard;
use Carbon\CarbonInterface;
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
        $this->info('e-DPC demo data ready. Full walkthrough: docs/SYSTEM_USER_MANUAL.md');
        $this->newLine();

        $this->line('  <options=bold>Password for every demo account:</> '.DemoAccountSeeder::PASSWORD);
        $this->newLine();

        $this->table(
            ['Role', 'Login (email or mobile)', 'Name'],
            $this->accounts(),
        );

        $this->table(['Record', 'Identifier'], $this->identifiers());

        $this->selfInspectionCheatSheet();
        $this->newScreens();
    }

    /**
     * The screens a presenter would otherwise have to be told about.
     *
     * Every one of these is reachable from the UI — that is deliberate, and it is
     * why they are listed here as URLs rather than as instructions: if a screen
     * needed explaining to be found, it would not be finished.
     */
    private function newScreens(): void
    {
        $this->newLine();
        $this->line('  <options=bold>Also worth showing</>');

        $this->table(['Screen', 'Who reaches it', 'Where'], [
            ['Reports', 'every role — it scopes itself', route('reports.index')],
            ['Notifications', 'every role, from the bell in the header', route('notifications.index')],
            ['Audit trail', 'CGPSC Admin', route('admin.audit-trail.index')],
            ['Upload templates', 'AO / AH, from the dashboard', route('dpc-import-templates.index')],
            ['Self-Inspection', 'the officer — no account', route('self-inspection.landing')],
        ]);
    }

    /**
     * Self-Inspection (S49–S51) has no account to hand out, so the demo needs the
     * three facts that open it instead.
     *
     * Read from the seeded chart rather than written down here: the officer this
     * prints is the officer the screen will actually admit, and a cheat sheet that
     * has drifted from the data is worse than none.
     */
    private function selfInspectionCheatSheet(): void
    {
        $factors = SelfInspectionFactor::configured();

        $row = app(SelfInspectionAccessGuard::class)->inspectableRows()->first();

        $this->newLine();
        $this->line('  <options=bold>Self-Inspection (S49–S51)</> — '.route('self-inspection.landing'));

        if (! $row instanceof DpcEvaluationRow) {
            $this->warn('  No proposal is currently open to self-inspection, so the screen has nothing to show.');
            $this->line('  It opens for proposals in: '
                .implode(', ', (array) config('dpc.self_inspection.visible_proposal_statuses', [])));

            return;
        }

        $rows = [];

        foreach ($factors as $factor) {
            $value = $row->{$factor->value};

            $rows[] = [
                $factor->label(),
                $value instanceof CarbonInterface ? $value->format('Y-m-d') : (string) $value,
            ];
        }

        $this->line('  There is no account — the officer proves who they are with these:');
        $this->table(['Field', 'Value'], $rows);
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
