<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Console\Commands\EvidenceGrantsCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * TD-1 — the evidence-table grants, and the list they are derived from.
 *
 * **The failure this exists to prevent is drift, not breakage — and it had
 * already happened.** The control was a markdown runbook naming two tables,
 * `dpc_calculation_runs` and `dpc_calculation_results`, written during M5. M6
 * then added allotments and schedules, M7 the proceeding, its corrections, its
 * holds and its documents, M8 the review chain, and M9 the circulation note:
 * **twelve evidence tables, of which the runbook covered two.** Nothing anywhere
 * said so, because a document has no way to notice a migration. Ten of them would
 * have gone to production unprotected with the deployment checklist ticked.
 *
 * This test is what found that. It is written first in this class for that
 * reason.
 *
 * So the assertion below is the one that matters: **every model that guards
 * itself against `update` must appear in `dpc.security.evidence_tables`.** The
 * guard is what makes a table evidence; if a table has one, its grants belong in
 * the runbook, and the runbook is now generated from that list.
 *
 * @see EvidenceGrantsCommand
 */
class EvidenceGrantsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The drift check.
     *
     * A model with a `static::updating` guard that throws is, by definition, a
     * record the application refuses to rewrite — which is exactly the property
     * a query-builder update can bypass and a database grant cannot.
     */
    public function test_every_immutable_model_appears_in_the_evidence_table_list(): void
    {
        $configured = array_keys($this->evidenceTables());
        $missing = [];

        foreach ($this->modelsThatGuardAgainstUpdate() as $class => $table) {
            if (! in_array($table, $configured, true)) {
                $missing[] = $table.' ('.$class.')';
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            [
                'These models refuse to be updated in the application but have no database grant',
                'behind them (TD-1). A query-builder mass update bypasses the model guard, so the',
                'guarantee is only as strong as the grant. Add them to dpc.security.evidence_tables:',
            ],
            $missing,
        )));
    }

    /**
     * And the reverse: nothing is listed that does not exist.
     *
     * A grant naming a dropped table is a statement that fails at deployment,
     * which is a bad moment to discover it.
     */
    public function test_every_listed_evidence_table_exists(): void
    {
        foreach (array_keys($this->evidenceTables()) as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "`{$table}` is listed as an evidence table but does not exist.",
            );
        }
    }

    /**
     * Every permitted column exists on its table.
     *
     * A column-level `GRANT UPDATE (typo)` is rejected by MySQL outright, so the
     * whole runbook fails on one stale name — and the person running it has no
     * way to tell a typo from a table that legitimately changed.
     */
    public function test_every_permitted_column_exists_on_its_table(): void
    {
        foreach ($this->evidenceTables() as $table => $rules) {
            foreach ($rules['update'] as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "`{$table}`.`{$column}` is granted UPDATE but is not a column on that table.",
                );
            }
        }
    }

    /**
     * The one grant that must not be tightened.
     *
     * Revoking `UPDATE` outright on `dpc_calculation_runs` breaks every
     * calculation, because completing a run is a single UPDATE of `status`. It is
     * the specific mistake the original runbook warned about in prose, and this
     * is that warning in a form that fails a build.
     */
    public function test_a_calculation_run_keeps_its_status_transition(): void
    {
        $tables = $this->evidenceTables();

        $this->assertArrayHasKey('dpc_calculation_runs', $tables);
        $this->assertContains(
            'status',
            $tables['dpc_calculation_runs']['update'],
            'Without column-level UPDATE on `status`, no calculation can complete.',
        );

        // And its results genuinely need none — they are written once.
        $this->assertSame([], $tables['dpc_calculation_results']['update']);
    }

    public function test_the_command_prints_a_revoke_and_a_grant_for_every_table(): void
    {
        $this->artisan('security:evidence-grants', ['--user' => 'odpc_app', '--host' => '10.%'])
            ->assertSuccessful();

        // Re-run capturing output, so the statements themselves are asserted
        // rather than only the exit code.
        $this->artisan('security:evidence-grants', ['--user' => 'odpc_app', '--database' => 'odpc'])
            ->expectsOutputToContain("REVOKE UPDATE, DELETE ON `odpc`.`dpc_calculation_runs` FROM 'odpc_app'@'%';")
            ->expectsOutputToContain("GRANT SELECT, INSERT ON `odpc`.`dpc_calculation_runs` TO 'odpc_app'@'%';")
            ->expectsOutputToContain("GRANT UPDATE (`status`) ON `odpc`.`dpc_calculation_runs` TO 'odpc_app'@'%';")
            ->expectsOutputToContain('FLUSH PRIVILEGES;')
            ->assertSuccessful();
    }

    /**
     * A table with no lifecycle gets no `GRANT UPDATE` line at all — not an
     * empty one, which MySQL would reject.
     */
    public function test_a_table_with_no_lifecycle_gets_no_update_grant(): void
    {
        Config::set('dpc.security.evidence_tables', [
            'dpc_calculation_results' => ['update' => []],
        ]);

        $this->artisan('security:evidence-grants', ['--database' => 'odpc'])
            ->expectsOutputToContain('REVOKE UPDATE, DELETE ON `odpc`.`dpc_calculation_results`')
            ->doesntExpectOutputToContain('GRANT UPDATE () ON')
            ->assertSuccessful();
    }

    /**
     * `--verify` refuses to report anything on an engine that has no grants.
     *
     * The suite runs on SQLite, which has no permission system, so a probe there
     * would find every write permitted and report a catastrophe — or, worse, be
     * written to skip and report a green. It says what it is instead.
     */
    public function test_verify_fails_rather_than_skipping_on_a_non_mysql_connection(): void
    {
        $this->artisan('security:evidence-grants', ['--verify' => true])
            ->expectsOutputToContain('Grants are a MySQL control')
            ->assertFailed();
    }

    public function test_it_reports_an_empty_configuration_rather_than_printing_nothing(): void
    {
        Config::set('dpc.security.evidence_tables', []);

        $this->artisan('security:evidence-grants')->assertFailed();
    }

    /**
     * @return array<string, array{update: list<string>}>
     */
    private function evidenceTables(): array
    {
        /** @var array<string, array{update: list<string>}> $tables */
        $tables = Config::get('dpc.security.evidence_tables', []);

        $this->assertNotEmpty($tables, 'No evidence tables are configured at all.');

        return $tables;
    }

    /**
     * Every domain model whose `booted()` installs an `updating` guard, mapped to
     * its table.
     *
     * Found by reading the source rather than by instantiating and poking at
     * events: the guards throw, and provoking each one would need a valid
     * instance of every aggregate. The question here is only *which models have
     * one*, and the source answers that directly.
     *
     * @return array<class-string, string>
     */
    private function modelsThatGuardAgainstUpdate(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (Finder::create()->files()->in(app_path('Domain'))->name('*.php')->path('Models') as $file) {
            $contents = (string) file_get_contents((string) $file->getRealPath());

            if (! str_contains($contents, 'static::updating')) {
                continue;
            }

            // Only a guard that *refuses* counts. A model that uses `updating`
            // to normalise a value is not evidence.
            if (! str_contains($contents, 'throw new RuntimeException')) {
                continue;
            }

            $class = 'App\\Domain\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                (string) $file->getRelativePathname(),
            );

            if (! class_exists($class)) {
                continue;
            }

            $found[$class] = (new $class)->getTable();
        }

        $this->assertNotEmpty($found, 'No immutable models were found — the finder is looking in the wrong place.');

        return $found;
    }
}
