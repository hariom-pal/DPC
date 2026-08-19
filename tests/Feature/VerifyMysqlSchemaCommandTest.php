<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The guards on `schema:verify-mysql` (TD-24 step 2).
 *
 * **What this test can and cannot cover, and why the split is deliberate.**
 *
 * The command's actual work — building all 49 migrations on a real MySQL server
 * in a throwaway database — cannot be asserted here: this suite runs on
 * in-memory SQLite, which is the very gap the command exists to close. Asserting
 * it would either require MySQL in the suite (a change to how every test runs,
 * not this command's business) or fall back to SQLite, which would test nothing.
 * **That build is verified by `composer gate`, on every phase, against MySQL.**
 *
 * What is worth pinning here is the pair of refusals, because both are load-
 * bearing and neither needs MySQL to demonstrate:
 *
 * 1. **It refuses a non-MySQL connection** rather than obligingly building the
 *    schema on whatever it is handed. Pointed at SQLite it would pass every
 *    time and prove nothing — a green check that cannot fail.
 * 2. **It fails rather than skipping** when the engine is unavailable. A skip is
 *    the false green TD-24 is about: four documents once recorded the gate as
 *    green on a table count taken before a migration failure.
 */
class VerifyMysqlSchemaCommandTest extends TestCase
{
    public function test_it_refuses_a_connection_that_is_not_mysql(): void
    {
        // The suite's own connection. Handing it to this command is the exact
        // mistake that would make the gate step meaningless.
        $this->artisan('schema:verify-mysql', ['--connection' => 'sqlite'])
            ->expectsOutputToContain('not MySQL')
            ->assertExitCode(1);
    }

    public function test_it_refuses_a_connection_that_does_not_exist(): void
    {
        $this->artisan('schema:verify-mysql', ['--connection' => 'no_such_connection'])
            ->expectsOutputToContain('No connection named')
            ->assertExitCode(1);
    }

    /**
     * An unreachable MySQL server is a failure, not a skip.
     *
     * Configured to a port nothing listens on, so the driver check passes and
     * the connection attempt is what fails — which is the branch that would
     * otherwise be tempting to turn into a skip in CI.
     */
    public function test_an_unreachable_mysql_server_fails_the_check_rather_than_skipping_it(): void
    {
        Config::set('database.connections.mysql_unreachable', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'dpc_unreachable',
            'username' => 'nobody',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ]);

        $this->artisan('schema:verify-mysql', ['--connection' => 'mysql_unreachable'])
            ->expectsOutputToContain('MySQL is unreachable')
            ->assertExitCode(1);
    }
}
