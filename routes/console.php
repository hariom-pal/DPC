<?php

declare(strict_types=1);

use App\Console\Commands\ProcessAssignmentRequestsCommand;
use App\Console\Commands\ReturnExpiredAllotmentsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| **A DPC not concluded by its deadline goes back to the CGPSC Chairman**
| (business clarification, 2026-09-05):
|
| > *If the DPC is not completed before the Completion Deadline, the system shall
| > automatically return the DPC to the CGPSC Chairman. The CGPSC Chairman may then
| > reassign the same DPC Allottee, assign another DPC Allottee, change the DPC
| > Chairman, or extend or change the Completion Deadline.*
|
| The command that does it has existed since the register of 2026-08-26 and was
| tested — but it was registered nowhere, so it never ran. "Automatically" was true
| of the code and false of the system. This is the line that closes that gap.
|
| **Daily, shortly after midnight**, because the deadline is a date rather than an
| instant: a DPC due on the 30th is overdue on the 31st, and there is nothing to be
| gained by sweeping more often than the unit the rule is written in.
|
| **`withoutOverlapping()`** because the sweep and its timeline writes are not
| something to run twice concurrently on a slow night; **`onOneServer()`** because
| the return must happen once however many application servers hold the schedule.
| The command is idempotent regardless — a row already carrying `returned_at` is
| excluded — and these are belt to that braces.
|
| Requires a system cron entry running `schedule:run` every minute. Without one
| this line is as inert as no line at all, which is precisely the failure it was
| written to fix, so it is stated in the deployment notes rather than assumed.
*/
Schedule::command(ReturnExpiredAllotmentsCommand::class)
    ->dailyAt('00:15')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Return DPCs not concluded by their completion date to the CGPSC Chairman');

// Transfers and additional charges take effect on their date, and additional
// charges end on theirs (Phases 7–8, 2026-10-27). Idempotent.
Schedule::command(ProcessAssignmentRequestsCommand::class)
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Apply approved transfers and additional charges, end expired additional charges');
