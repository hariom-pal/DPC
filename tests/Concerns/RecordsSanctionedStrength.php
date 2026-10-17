<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Models\VacancyPositionSanctionedStrength;
use App\Domain\Dpc\Services\SanctionedStrengthSource;

/**
 * Record a sanctioned strength where the application reads it: the Vacancy Master,
 * for the proposal's department, Promotion To Post and DPC year.
 *
 * Written directly, like the other fixtures: a test about vacancies or scoring
 * should not depend on the Administrator's Vacancy Master screens.
 */
trait RecordsSanctionedStrength
{
    protected function recordSanctionedStrength(DpcProposal $proposal, ?int $total): void
    {
        $proposal->loadMissing('promotionToPost');
        $post = $proposal->promotionToPost;

        if ($post === null) {
            return;
        }

        $year = app(SanctionedStrengthSource::class)->yearOf($proposal);

        $position = VacancyPosition::query()
            ->where('department_id', $proposal->department_id)
            ->where('post_name', $post->name)
            ->where('vacancy_year', $year)
            ->orderByDesc('revision')
            ->first()
            ?? VacancyPosition::query()->create([
                'department_id' => $proposal->department_id,
                'post_name' => $post->name,
                'department_post_id' => $post->id,
                'vacancy_year' => $year,
                'revision' => 1,
                'status' => VacancyPositionStatus::Draft->value,
                'period_from' => "{$year}-01-01",
                'period_to' => "{$year}-12-31",
            ]);

        if ($total === null) {
            $position->forceFill(['sanctioned_strength_id' => null, 'sanctioned_strength' => null])->save();

            return;
        }

        $record = VacancyPositionSanctionedStrength::query()->create([
            'vacancy_position_id' => $position->id,
            'sanctioned_total' => $total,
            'order_number' => 'GO/FIXTURE/SANCTION',
            'order_date' => "{$year}-01-01",
            'disk' => 'local',
            'path' => 'private/fixtures/sanction.pdf',
            'original_name' => 'sanction.pdf',
            'size_bytes' => 1,
            'sha256' => str_repeat('0', 64),
            'recorded_at' => now(),
        ]);

        $position->forceFill([
            'sanctioned_strength_id' => $record->id,
            // A finalized position reports its frozen figure; keep the two in step.
            'sanctioned_strength' => $position->status === VacancyPositionStatus::Finalized ? $total : $position->sanctioned_strength,
        ])->save();
    }
}
