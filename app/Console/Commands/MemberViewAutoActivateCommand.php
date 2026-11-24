<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Proceeding\Services\MemberViewService;
use Illuminate\Console\Command;

/**
 * Records, once, each allotted DPC opened to its committee members on its own
 * because the DPC Chairman's days passed (2026-11-24). The members see it from the
 * deadline whether or not this has run; this puts the opening on the DPC's timeline.
 * Safe to run as often as wanted.
 */
class MemberViewAutoActivateCommand extends Command
{
    protected $signature = 'dpc:member-view-auto-activate';

    protected $description = 'Record allotted DPCs opened to the committee members on their own once the DPC Chairman\'s days passed';

    public function handle(MemberViewService $views): int
    {
        $count = $views->recordAutoActivations();
        $this->info($count.' DPC(s) opened to the committee members on their own.');

        return self::SUCCESS;
    }
}
