<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dpc\Enums\VacancyEntryKind;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use App\Domain\Dpc\Services\VacancyMasterService;
use Illuminate\Console\Command;

/**
 * The draft Vacancy Management records whose working figure may count an officer
 * twice (2026-11-04).
 *
 * Working = the figure entered + B1 + B2 + C since 2026-11-04. A draft whose figure
 * was entered before then, when our officers on deputation elsewhere were to be
 * included in it, may already hold its B2 and C officers. Read-only: the figure is
 * the department's to correct. Finalized records answer from their frozen snapshot
 * and are not listed.
 */
class ReviewVacancyDraftWorkingCommand extends Command
{
    protected $signature = 'vacancy:review-draft-working';

    protected $description = 'List draft Vacancy Management records with B2 or C rows, whose working figure must leave those officers out';

    public function handle(VacancyMasterService $vacancies): int
    {
        $drafts = VacancyPosition::query()
            ->where('status', 'draft')
            ->with(['department', 'workingStrength', 'entries'])
            ->orderBy('id')
            ->get()
            ->filter(fn (VacancyPosition $position): bool => $position->workingStrength !== null && $this->named($position, VacancyEntryKind::Deputation) + $this->named($position, VacancyEntryKind::Anticipated) > 0);

        if ($drafts->isEmpty()) {
            $this->info('No draft record has B2 or C rows: nothing can be counted twice.');

            return self::SUCCESS;
        }

        $this->warn($drafts->count().' draft record(s) to review. The figure entered must leave out the B1, B2 and C officers, who are added to it:');
        $this->table(
            ['Record', 'Department', 'Post', 'Year', 'Entered', 'B1', 'B2', 'C', 'Working now'],
            $drafts->map(fn (VacancyPosition $position): array => [
                $position->id,
                $position->department?->name,
                $position->post_name,
                $position->vacancy_year,
                $position->workingStrength?->working_total,
                $this->named($position, VacancyEntryKind::DeputationIn),
                $this->named($position, VacancyEntryKind::Deputation),
                $this->named($position, VacancyEntryKind::Anticipated),
                $vacancies->breakdown($position)['working']['total'] ?? null,
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function named(VacancyPosition $position, VacancyEntryKind $kind): int
    {
        return (int) $position->entries
            ->filter(fn (VacancyPositionEntry $entry): bool => $entry->isActive() && $entry->kind === $kind)
            ->sum('vacancy_count');
    }
}
