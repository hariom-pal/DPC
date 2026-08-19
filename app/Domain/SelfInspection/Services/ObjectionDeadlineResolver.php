<?php

declare(strict_types=1);

namespace App\Domain\SelfInspection\Services;

use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Support\Carbon;

/**
 * The date in S51's notice — *"...दिनांक 25 जून 2026 या उसके पूर्व..."*.
 *
 * **The deck shows a date and says nothing about where it comes from.** Rather
 * than hardcode one or invent a rule, it is derived from the scheduled sitting
 * under `dpc.self_inspection.objection_deadline`, and **when it cannot be
 * derived the notice is shown with no date at all**.
 *
 * That last part is the whole design. The notice's substance — raise a correction
 * **with the department, in person**, because the Commission will consider no
 * representation — is unconditional and true whether or not a sitting has been
 * scheduled. Only the deadline depends on the calendar. A screen that invented
 * "30 days from today" would be telling an officer a date no one had decided,
 * and on a screen whose whole purpose is to show them what the record says.
 */
class ObjectionDeadlineResolver
{
    /**
     * The date by which the officer must raise a correction, or null if none can
     * be derived.
     */
    public function resolve(DpcProposal $proposal): ?Carbon
    {
        $basis = (string) config('dpc.self_inspection.objection_deadline.basis', 'days_before_sitting');

        if ($basis !== 'days_before_sitting') {
            // `none`, or anything unrecognised. An unrecognised basis is treated
            // as `none` rather than as an error: a typo in configuration must not
            // take the screen down, and showing no date is the safe outcome.
            return null;
        }

        $sitting = $this->sittingDate($proposal);

        if ($sitting === null) {
            return null;
        }

        $days = (int) config('dpc.self_inspection.objection_deadline.days_before_sitting', 15);

        $deadline = $sitting->copy()->subDays(max($days, 0));

        // A deadline already in the past is still the truth and is still shown:
        // the officer needs to know they have missed it, which is precisely what
        // S51's notice warns about. It is `closed` state that stops them acting,
        // not a suppressed date.
        return $deadline;
    }

    /**
     * The current scheduled sitting date for this proposal, if there is one.
     *
     * Reads the current allotment's live schedule. A re-allotted or re-scheduled
     * DPC therefore yields the date now in force, not the superseded one — which
     * is the same rule M6 applies everywhere else.
     */
    public function sittingDate(DpcProposal $proposal): ?Carbon
    {
        $allotment = DpcAllotment::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->current()
            ->latest('id')
            ->first();

        if ($allotment === null) {
            return null;
        }

        $schedule = DpcSchedule::query()
            ->where('dpc_allotment_id', $allotment->id)
            ->whereNull('superseded_by_id')
            ->latest('id')
            ->first();

        $date = $schedule?->dpc_date;

        return $date instanceof Carbon ? $date->copy() : null;
    }
}
