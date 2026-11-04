<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dpc\Enums\VacancyEntryKind;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use App\Domain\Dpc\Services\VacancyMasterService;
use Illuminate\Console\Command;

/**
 * The draft Vacancy Management records whose working figure must be checked
 * against their B1, B2 and C rows (2026-11-04).
 *
 * The working strength entered is every officer working on the post on 1 January,
 * B1, B2 and C included; those rows are among them. A draft whose figure was
 * entered while the rows were added to it may leave them out. Read-only: the figure
 * is the department's to correct. Finalized records answer from their frozen
 * snapshot and are not listed.
 */
class ReviewVacancyDraftWorkingCommand extends Command
{
    protected $signature = 'vacancy:review-draft-working';

    protected $description = 'List draft Vacancy Management records with B1, B2 or C rows, whose working figure must include those officers';

    public function handle(VacancyMasterService $vacancies): int
    {
        $drafts = VacancyPosition::query()
            ->where('status', 'draft')
            ->with(['department', 'workingStrength', 'entries'])
            ->orderBy('id')
            ->get()
            ->filter(fn (VacancyPosition $position): bool => $position->workingStrength !== null && $this->named($position, VacancyEntryKind::DeputationIn) + $this->named($position, VacancyEntryKind::Deputation) + $this->named($position, VacancyEntryKind::Anticipated) > 0);

        if ($drafts->isEmpty()) {
            $this->info('No draft record has B1, B2 or C rows: nothing to check.');

            return self::SUCCESS;
        }

        $this->warn($drafts->count().' draft record(s) to review. The working strength entered must include the B1, B2 and C officers, who are among the working officers:');
        $this->table(
            ['Record', 'Department', 'Post', 'Year', 'Working', 'B1', 'B2', 'C', 'Others'],
            $drafts->map(fn (VacancyPosition $position): array => [
                $position->id,
                $position->department?->name,
                $position->post_name,
                $position->vacancy_year,
                $position->workingStrength?->working_total,
                $this->named($position, VacancyEntryKind::DeputationIn),
                $this->named($position, VacancyEntryKind::Deputation),
                $this->named($position, VacancyEntryKind::Anticipated),
                $vacancies->breakdown($position)['working']['own'] ?? null,
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
