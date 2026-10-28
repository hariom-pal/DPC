<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\UserManagement\Services\AssignmentRequestService;
use Illuminate\Console\Command;

/**
 * Put approved transfers and additional charges into effect on their date, and
 * end additional charges past their end date (Phases 7–8, 2026-10-27).
 * Idempotent: each request takes effect once, under a row lock.
 */
class ProcessAssignmentRequestsCommand extends Command
{
    protected $signature = 'dpc:process-assignments';

    protected $description = 'Apply approved transfers and additional charges that are due, and end expired additional charges';

    public function handle(AssignmentRequestService $service): int
    {
        $result = $service->processDue();
        $this->info(sprintf('Took effect: %d. Failed: %d. Additional charges ended: %d.', $result['effective'], $result['failed'], $result['expired']));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
