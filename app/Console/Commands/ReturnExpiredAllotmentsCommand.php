<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cgpsc\Services\AllotmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Send DPCs whose completion date has passed back to the Chairman (change register
 * of 2026-08-26, item 9 as clarified).
 *
 * > *If the member does not conclude the DPC by the date it was supposed to be
 * > concluded, the DPC returns to the Chairman, and the Chairman allocates it
 * > again.*
 *
 * **A scheduled command rather than a check on read**, and the reason is that the
 * return has to be a recorded event. Deriving "overdue" whenever a screen is opened
 * would make the DPC appear to return at a different moment for each reader, and
 * would leave no timeline entry saying when it happened or that it happened at all.
 * A DPC leaving one member's hands is a fact somebody will be asked about.
 *
 * **Idempotent.** A row that has already been returned carries `returned_at` and is
 * excluded, so running this twice on the same day returns nothing the second time.
 * That matters because the natural failure mode of a scheduled task is running more
 * often than intended, not less.
 */
class ReturnExpiredAllotmentsCommand extends Command
{
    protected $signature = 'dpc:return-expired-allotments
        {--as-of= : Treat this date as today (YYYY-MM-DD), for verification}
        {--dry-run : List what would be returned and change nothing}';

    protected $description = 'Return DPCs not concluded by their completion date to the Chairman for re-allotment';

    public function handle(AllotmentService $allotments): int
    {
        $asOf = $this->option('as-of') !== null
            ? Carbon::parse((string) $this->option('as-of'))
            : Carbon::today();

        $overdue = $allotments->overdue($asOf);

        if ($overdue->isEmpty()) {
            $this->info('No allotment has passed its completion date.');

            return self::SUCCESS;
        }

        $this->table(
            ['DPC', 'Allotted to', 'Due', 'Days over'],
            $overdue->map(fn ($allotment): array => [
                $allotment->proposal->dpc_code ?? '—',
                $allotment->allottee->name ?? '—',
                $allotment->complete_by?->format('d-m-Y') ?? '—',
                $allotment->complete_by === null ? '—' : $allotment->complete_by->diffInDays($asOf),
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $returned = $allotments->returnExpired($asOf);

        $this->newLine();
        $this->info(sprintf('%d allotment(s) returned to the Chairman.', $returned->count()));

        /*
         | The difference is not an error. An overdue allotment whose sitting has
         | since concluded is deliberately left alone — the member did the work,
         | late — so it is listed above and not returned. Saying so stops the gap
         | being read as a partial failure.
         */
        $skipped = $overdue->count() - $returned->count();

        if ($skipped > 0) {
            $this->line(sprintf('%d were left alone: their sitting has already concluded.', $skipped));
        }

        return self::SUCCESS;
    }
}
