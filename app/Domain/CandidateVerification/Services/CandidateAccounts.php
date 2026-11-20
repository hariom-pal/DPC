<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRow;

/**
 * The candidate's own portal account (the Promotion Candidate the department's
 * Seniority List provisioned), found as PromotionCandidateProvisioner keys it: the
 * employee number, within the DPC's department. It signs the candidate's verification
 * and gives the e-mail address where one is held. Never found by name.
 */
final class CandidateAccounts
{
    public function userFor(DpcEvaluationRow $row): ?User
    {
        $employee = trim((string) $row->employee_number);
        $department = (int) $row->proposal?->department_id;

        if ($employee === '' || $department === 0) {
            return null;
        }

        $matches = User::query()
            ->where('employee_number', $employee)
            ->whereHas('assignments', fn ($query) => $query->where('department_id', $department))
            ->limit(2)
            ->get();

        // Two accounts on one number and department is a data fault, not a choice to make.
        return $matches->count() === 1 ? $matches->first() : null;
    }
}
