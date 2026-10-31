<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use App\Domain\Dpc\Models\VacancyPositionSanctionedStrength;
use App\Domain\Dpc\Models\VacancyPositionWorkingStrength;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Delete the Vacancy Management drafts kept before the record became one per
 * Pre-DPC (decisions of 2026-10-30: "UAT test data hai, hata do").
 *
 * **Only drafts with no Pre-DPC.** A finalized or superseded record may be pinned to
 * a submitted DPC proposal and is history; it is never touched. A draft opened since
 * 2026-10-30 names its Pre-DPC and is kept.
 *
 * **A dry run unless `--apply` is given**, so the list can be read first. The PDFs on
 * the private disk are left where they are: a revision copies its predecessor's
 * document rows, so a path can be shared with a finalized record.
 */
class PurgeOldVacancyDraftsCommand extends Command
{
    protected $signature = 'dpc:purge-old-vacancy-drafts
        {--apply : Delete them; without it the drafts are only listed}';

    protected $description = 'Delete Vacancy Management drafts kept before the record became one per Pre-DPC (dry run by default)';

    public function handle(): int
    {
        /** @var Collection<int, VacancyPosition> $drafts */
        $drafts = VacancyPosition::query()
            ->where('status', VacancyPositionStatus::Draft->value)
            ->whereNull('pre_dpc_proposal_id')
            ->with('department')
            ->withCount('entries')
            ->orderBy('department_id')
            ->orderBy('post_name')
            ->orderBy('vacancy_year')
            ->get();

        if ($drafts->isEmpty()) {
            $this->info('No old Vacancy Management draft found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Department', 'Post', 'Year', 'Revision', 'Rows', 'Created'],
            $drafts->map(fn (VacancyPosition $draft): array => [
                $draft->id,
                $draft->department->name ?? '—',
                $draft->post_name,
                $draft->vacancy_year,
                $draft->revision,
                $draft->entries_count,
                $draft->created_at?->format('d-m-Y H:i') ?? '—',
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info(sprintf('Dry run — %d draft(s) listed, nothing was changed. Run again with --apply to delete them.', $drafts->count()));

            return self::SUCCESS;
        }

        $ids = $drafts->pluck('id')->all();

        DB::transaction(function () use ($ids): void {
            // The record points at its current sanctioned and working rows, and they
            // at it: clear the pointers before the rows go.
            VacancyPosition::query()->whereIn('id', $ids)->update(['sanctioned_strength_id' => null, 'working_strength_id' => null]);
            VacancyPositionEntry::query()->whereIn('vacancy_position_id', $ids)->delete();
            VacancyPositionSanctionedStrength::query()->whereIn('vacancy_position_id', $ids)->delete();
            VacancyPositionWorkingStrength::query()->whereIn('vacancy_position_id', $ids)->delete();
            VacancyPosition::query()->whereIn('id', $ids)->delete();
        });

        $this->newLine();
        $this->info(sprintf('%d old draft(s) deleted.', count($ids)));

        return self::SUCCESS;
    }
}
