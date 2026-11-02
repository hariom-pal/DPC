<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Services\EmployeeDirectory;
use Illuminate\Console\Command;

/**
 * Fill the employee database (2026-11-02) from what the portal already holds: every
 * DPC's Working Officers and Seniority Lists, Vacancy Management rows and the
 * officers' accounts, oldest first so the latest details win. Safe to run again.
 * From then on the lists and rows keep it up to date as they are saved.
 */
class SyncEmployeesCommand extends Command
{
    protected $signature = 'dpc:sync-employees';

    protected $description = 'Fill the employee database from the DPC lists, Vacancy Management and user accounts';

    public function handle(EmployeeDirectory $employees): int
    {
        $before = Employee::query()->count();

        DpcProposal::query()->withTrashed()->with('promotionToPost', 'promotionFromPost')->orderBy('id')->each(function (DpcProposal $proposal) use ($employees): void {
            $employees->recordWorkingOfficers($proposal);
            $employees->recordSeniorityList($proposal);
        });

        VacancyPositionEntry::query()->with('position')->orderBy('id')->each(fn (VacancyPositionEntry $entry) => $employees->recordVacancyEntry($entry));

        User::query()->whereNotNull('employee_number')->where('employee_number', '!=', '')->orderBy('id')
            ->each(fn (User $user) => $employees->recordUser($user));

        $after = Employee::query()->count();
        $this->info(sprintf('Employee database: %d officer(s), %d new.', $after, $after - $before));

        return self::SUCCESS;
    }
}
