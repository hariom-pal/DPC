<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Events;

use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A DPC sitting has been fixed, or moved (business clarification, 2026-09-05).
 *
 * > *Once the DPC Allottee schedules the meeting, automatic SMS and Email
 * > notifications shall be sent to: All Committee Members, All Candidates.*
 *
 * ### Why an event of its own rather than the workflow transition
 *
 * `WorkflowTransitioned` already fires on `schedule` and `reschedule`, and
 * `SendWorkflowNotification` already listens. It could have carried this — and
 * that is exactly what makes it the wrong place. That listener sends *"ODPC:
 * dpc_cgpsc was 'schedule' (status: scheduled)"* to a fixed audience of workflow
 * participants: the department's officers and the allottee. What the clarification
 * asks for is a different message to a different and much larger audience — every
 * member of the committee and every officer under consideration — carrying the
 * date, the time and joining instructions.
 *
 * Widening the workflow listener to do both would have meant one listener deciding
 * which of two audiences and which of two messages applied, keyed on the event
 * name. Two events, two listeners, and each says one thing to one audience.
 *
 * ### The schedule travels with it, not just the proposal
 *
 * A re-schedule writes a new row and supersedes the old one, so "the current
 * schedule" is a query whose answer depends on when it is asked. The listener runs
 * on a queue, possibly after the next re-schedule — so it is handed the row it is
 * announcing and never looks one up.
 */
class DpcSittingScheduled
{
    use Dispatchable;

    public function __construct(
        public readonly DpcProposal $proposal,
        public readonly DpcSchedule $schedule,

        /**
         * Whether this replaces a date that had already been announced, so the
         * message can say "has been moved to" rather than "has been fixed for".
         * Telling a committee member a DPC is "scheduled" for the third time
         * without saying it changed is how somebody attends on the wrong day.
         */
        public readonly bool $isReschedule = false,
    ) {}
}
