<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\VacancyPosition;

/**
 * The sanctioned strength of a post for a year — from the Vacancy Master.
 *
 * It used to be a figure on the Department Post Mapping. A sanctioned strength can
 * change from one year to the next on its own order, so the CGPSC Administrator now
 * records it in the Vacancy Master for each Vacancy Year (1 January – 31 December),
 * with the sanctioning order and its PDF, and every reader asks here.
 *
 * For a year: the finalized position in force (its frozen figure), or else the
 * figure recorded on the latest revision. Null when none is recorded — never zero,
 * because "not recorded" and "nothing sanctioned" mean opposite things.
 */
final class SanctionedStrengthSource
{
    public function forProposal(DpcProposal $proposal): ?int
    {
        $post = $proposal->promotionToPost;

        if ($post === null) {
            return null;
        }

        return $this->for((int) $proposal->department_id, $post->name, $this->yearOf($proposal));
    }

    public function for(int $departmentId, string $postName, int $year): ?int
    {
        $positions = VacancyPosition::query()
            ->with('sanctionedStrength')
            ->where('department_id', $departmentId)
            ->where('post_name', $postName)
            ->where('vacancy_year', $year)
            ->orderByDesc('revision')
            ->get();

        $finalized = $positions->first(fn (VacancyPosition $position): bool => $position->status === VacancyPositionStatus::Finalized);

        if ($finalized !== null) {
            return $finalized->sanctioned_strength ?? $finalized->sanctionedStrength?->sanctioned_total;
        }

        return $positions->first()?->sanctionedStrength?->sanctioned_total;
    }

    public function yearOf(DpcProposal $proposal): int
    {
        return (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);
    }
}
