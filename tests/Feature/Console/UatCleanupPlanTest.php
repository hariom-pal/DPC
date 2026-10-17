<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Maintenance\UatCleanupPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The classification behind the planned `dpc:uat-cleanup`: every table of the schema
 * is in exactly one list, and the data UAT must keep is in the preserved ones.
 */
class UatCleanupPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_in_the_schema_is_classified_exactly_once(): void
    {
        $tables = Schema::getTableListing(Schema::getCurrentSchemaListing(), false);
        $this->assertGreaterThan(50, count($tables), 'The schema listing returned almost nothing.');

        $classified = [...array_keys(UatCleanupPlan::CLEAN), ...array_keys(UatCleanupPlan::PARTIAL), ...array_keys(UatCleanupPlan::PRESERVE)];

        $this->assertSame([], array_values(array_diff_assoc($classified, array_unique($classified))), 'A table is in more than one list.');
        $this->assertSame([], array_values(array_diff($tables, $classified)), 'Classify the new table in UatCleanupPlan.');
        $this->assertSame([], array_values(array_diff($classified, $tables)), 'UatCleanupPlan names a table the schema does not have.');
    }

    public function test_the_data_uat_must_keep_is_never_emptied(): void
    {
        foreach (['departments', 'department_posts', 'department_post_orders', 'roles', 'permissions', 'role_has_permissions', 'recruitment_rules', 'cgpsc_chairmen', 'migrations'] as $table) {
            $this->assertSame('preserve', UatCleanupPlan::classify($table), $table);
        }

        foreach (['users', 'model_has_roles', 'user_department_assignments'] as $table) {
            $this->assertSame('partial', UatCleanupPlan::classify($table), $table);
        }

        foreach (['dpc_proposals', 'vacancy_positions', 'dpc_proposal_verifications', 'workflow_transitions', 'otp_verifications', 'sessions', 'jobs', 'failed_jobs'] as $table) {
            $this->assertSame('clean', UatCleanupPlan::classify($table), $table);
        }

        $this->assertSame('unknown', UatCleanupPlan::classify('some_future_table'));
    }

    public function test_every_file_reference_names_a_real_column(): void
    {
        foreach (UatCleanupPlan::FILE_REFERENCES as $table => $pairs) {
            foreach ($pairs as [$disk, $path]) {
                $this->assertTrue(Schema::hasColumn($table, $path), "{$table}.{$path}");

                if (! str_starts_with($disk, 'config:')) {
                    $this->assertTrue(Schema::hasColumn($table, $disk), "{$table}.{$disk}");
                }
            }
        }
    }
}
