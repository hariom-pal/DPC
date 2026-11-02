<?php

declare(strict_types=1);

namespace App\Domain\Employee\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\VacancyEntryKind;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcSeniorityEntry;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use App\Domain\Employee\Models\Employee;
use DateTimeInterface;

/**
 * Keeps the employee database (2026-11-02): one row per Employee ID, updated with
 * whatever the portal is told about the officer.
 *
 * **The latest non-empty value wins; a blank never erases.** A Seniority List with no
 * mobile does not remove the mobile a Working Officers List gave. The email comes
 * from the Working Officers List's EMAIL column, and the officer's own account's
 * email, where there is one, takes precedence.
 *
 * **Only the department's own officers.** An officer here on deputation (B1, HERE ON
 * DEPUTATION) carries another department's Employee ID, which this department cannot
 * vouch for, so they are not recorded.
 */
final class EmployeeDirectory
{
    /**
     * @param  array{name?: string|null, email?: string|null, mobile?: string|null, date_of_birth?: DateTimeInterface|string|null, qualification?: string|null, current_post?: string|null, date_of_joining_service?: DateTimeInterface|string|null, date_of_retirement?: DateTimeInterface|string|null}  $details
     */
    public function record(?string $employeeNumber, array $details, ?int $departmentId, string $source): ?Employee
    {
        $number = trim((string) $employeeNumber);

        if ($number === '') {
            return null;
        }

        $values = array_filter(
            array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $details),
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $user = User::query()->where('employee_number', $number)->first();

        if ($user !== null) {
            $values['user_id'] = $user->id;

            if (! empty($user->email)) {
                $values['email'] = $user->email;
            }
        }

        $employee = Employee::query()->firstOrNew(['employee_number' => mb_substr($number, 0, 60)]);

        if (! $employee->exists && empty($values['name'])) {
            return null;   // nothing to call them by
        }

        $employee->fill([
            ...$values,
            ...($departmentId !== null ? ['department_id' => $departmentId] : []),
            'source' => $source,
            'last_seen_at' => now(),
        ])->save();

        return $employee;
    }

    /**
     * A DPC's Working Officers List: the officers on the post promoted into.
     */
    public function recordWorkingOfficers(DpcProposal $proposal): void
    {
        $post = $proposal->promotionToPost?->name;

        foreach ($proposal->workingOfficers()->get() as $officer) {
            /** @var DpcWorkingOfficer $officer */
            if ($officer->status === DpcWorkingOfficer::STATUS_VACANT || $officer->source === DpcWorkingOfficer::SOURCE_DEPUTATION) {
                continue;
            }

            $this->record($officer->employee_number, [
                'name' => $officer->name,
                'email' => $officer->email,
                'mobile' => $officer->mobile,
                'date_of_birth' => $officer->dob,
                'qualification' => $officer->qualification,
                'current_post' => $officer->promotion_to_post ?? $post,
                'date_of_joining_service' => $officer->date_of_joining_service,
                'date_of_retirement' => $officer->date_of_retirement,
            ], $proposal->department_id, Employee::SOURCE_WORKING_LIST);
        }
    }

    /**
     * A DPC's Seniority List: the officers of the feeder post.
     */
    public function recordSeniorityList(DpcProposal $proposal): void
    {
        $post = $proposal->promotionFromPost?->name;

        foreach ($proposal->seniorityEntries()->get() as $entry) {
            /** @var DpcSeniorityEntry $entry */
            $this->record($entry->employee_number, [
                'name' => $entry->name,
                'mobile' => $entry->mobile,
                'date_of_birth' => $entry->dob,
                'qualification' => $entry->qualification,
                'current_post' => $entry->promotion_from_post ?? $post,
                'date_of_joining_service' => $entry->date_of_joining_service,
            ], $proposal->department_id, Employee::SOURCE_SENIORITY_LIST);
        }
    }

    /**
     * A Vacancy Management row of one of our officers: away on deputation (B2), or
     * retiring or promoted to a higher post during the year (C).
     */
    public function recordVacancyEntry(VacancyPositionEntry $entry): void
    {
        if ($entry->kind === VacancyEntryKind::DeputationIn) {
            return;
        }

        $position = $entry->position;

        $this->record($entry->employee_number, [
            'name' => $entry->officer_name,
            'current_post' => $position?->post_name,
        ], $position?->department_id, Employee::SOURCE_VACANCY_MANAGEMENT);
    }

    /**
     * An officer's own account: name, email, mobile and date of birth.
     */
    public function recordUser(User $user): void
    {
        $this->record($user->employee_number, [
            'name' => $user->name,
            'mobile' => $user->mobile,
            'date_of_birth' => $user->date_of_birth,
        ], $user->department_id, Employee::SOURCE_USER_ACCOUNT);
    }
}
