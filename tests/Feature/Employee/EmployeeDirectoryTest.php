<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Services\EmployeeDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The employee database (2026-11-02): one row per Employee ID, the latest non-empty
 * details winning, the email taken from the officer's own account.
 */
class EmployeeDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_latest_details_win_and_a_blank_never_erases(): void
    {
        $department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'registered']);
        $directory = app(EmployeeDirectory::class);

        $directory->record(' E-1 ', ['name' => 'R. Sharma', 'mobile' => '9425012345', 'date_of_birth' => '1975-01-01'], $department->id, Employee::SOURCE_WORKING_LIST);
        $directory->record('E-1', ['name' => 'Shri R. Sharma', 'mobile' => '', 'qualification' => 'M.A.'], null, Employee::SOURCE_SENIORITY_LIST);

        $employee = Employee::query()->sole();
        $this->assertSame(['E-1', 'Shri R. Sharma', '9425012345', 'M.A.', '1975-01-01', $department->id, Employee::SOURCE_SENIORITY_LIST], [
            $employee->employee_number, $employee->name, $employee->mobile, $employee->qualification,
            $employee->date_of_birth?->toDateString(), $employee->department_id, $employee->source,
        ]);

        // No Employee ID, or no name for a new officer: nothing is recorded.
        $this->assertNull($directory->record('', ['name' => 'X'], null, Employee::SOURCE_WORKING_LIST));
        $this->assertNull($directory->record('E-2', ['name' => ''], null, Employee::SOURCE_WORKING_LIST));
        $this->assertSame(1, Employee::query()->count());
    }

    public function test_the_email_and_the_login_come_from_the_officers_account_and_the_command_fills_it_again(): void
    {
        $user = User::factory()->create(['email' => 'r.sharma@example.gov.in']);
        $user->employee_number = 'E-1';
        $user->save();

        app(EmployeeDirectory::class)->record('E-1', ['name' => 'R. Sharma'], null, Employee::SOURCE_SENIORITY_LIST);

        $employee = Employee::query()->sole();
        $this->assertSame([$user->id, 'r.sharma@example.gov.in'], [$employee->user_id, $employee->email]);

        // Built again from what the portal holds, idempotently.
        DB::table('employees')->delete();
        $this->artisan('dpc:sync-employees')->assertSuccessful();
        $this->artisan('dpc:sync-employees')->expectsOutputToContain('1 officer(s), 0 new.')->assertSuccessful();
        $this->assertSame('r.sharma@example.gov.in', Employee::query()->sole()->email);
    }
}
