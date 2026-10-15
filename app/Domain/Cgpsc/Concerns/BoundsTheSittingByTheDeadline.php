<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Concerns;

use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * *"Meeting Date must always be on or before the Completion Deadline assigned by
 * the CGPSC Chairman. If rescheduled, the new meeting date must also remain within
 * the Completion Deadline."* — business clarification, 2026-09-05.
 *
 * ### Why this is a form rule as well as a service rule
 *
 * `SchedulingService::assertWithinCompletionDate()` has enforced this since the
 * register of 2026-08-26 and still does. It is the guard that binds every caller,
 * including the ones that never see a form, and nothing here replaces it.
 *
 * What it cannot do is put the message **against the date box**. A
 * `WorkflowException` becomes a page-level error banner after a redirect, with the
 * offending date already discarded — so an allottee who typed a date a week past
 * the deadline gets a paragraph at the top of the screen and an empty field. That
 * is the difference this trait makes, and it is the whole of it.
 *
 * ### Two rules, one wording
 *
 * The refusal text is built here and thrown by the service, so scheduling and
 * re-scheduling report the same fault the same way whichever layer catches it
 * first. A second sentence would eventually become a second rule.
 *
 * ### An allotment with no deadline is unbounded
 *
 * Allotments made before the deadline existed carry none, and those are left alone:
 * there is no date to be late for, and inventing one would make a historical
 * allotment retrospectively overdue. Same reasoning as the service.
 */
trait BoundsTheSittingByTheDeadline
{
    /**
     * Refuse a sitting date that falls after the date the DPC must be concluded by.
     *
     * Registered from the request's `withValidator()`. Silent when the date is
     * missing or unparseable — `required` and `date` have already said so, and a
     * second message about the same box is noise.
     */
    protected function assertSittingIsWithinTheDeadline(Validator $validator, string $field = 'dpc_date'): void
    {
        $proposal = $this->route('dpcProposal');

        if (! $proposal instanceof DpcProposal) {
            return;
        }

        $completeBy = app(AllotmentService::class)->current($proposal)?->complete_by;

        if ($completeBy === null) {
            return;
        }

        $value = $this->input($field);

        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return;
        }

        if ($date->lte($completeBy)) {
            return;
        }

        $validator->errors()->add($field, (string) __('cgpsc.schedules.beyond_deadline', [
            'deadline' => $completeBy->format('d-m-Y'),
            'chosen' => $date->format('d-m-Y'),
        ]));
    }
}
