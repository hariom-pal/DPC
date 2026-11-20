<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CandidateVerification\Services\VerificationRoutingService;
use Illuminate\Console\Command;

/**
 * Closes every candidate verification whose deadline has passed (2026-11-19): No
 * Response for whoever submitted nothing, then the DPC to the Secretary (no grievance)
 * or to the AH's grievance review. Safe to run as often as wanted.
 */
class CandidateVerificationDeadlinesCommand extends Command
{
    protected $signature = 'dpc:candidate-verification-deadlines';

    protected $description = 'Close candidate verifications past their deadline and route each DPC on';

    public function handle(VerificationRoutingService $routing): int
    {
        $closed = $routing->closeExpired();
        $this->info($closed.' candidate verification(s) closed.');

        return self::SUCCESS;
    }
}
