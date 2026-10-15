<?php

declare(strict_types=1);

namespace App\Domain\SelfInspection\Services;

use App\Domain\Dpc\Models\DpcEvaluationRow;
use Illuminate\Support\Carbon;

/**
 * The S51 screen's contents, assembled once and rendered twice — once on screen
 * and once for PRINT.
 *
 * **Exactly the columns S51 shows, and nothing more:** name, DOB, qualification,
 * date of posting, category, year-wise loyalty, year-wise immovable-property
 * details, year-wise CR, and the note.
 *
 * **What is deliberately absent is the point.** No MARK, no REMARK, no zone, no
 * super-seat, no waiting-list placement, no seniority number — none of it. The
 * officer is being shown *the data their department supplied*, which is what S51
 * says on its face: *"DATA BEING CONSIDER AS PROVIDED BY YOUR DEPARTMENT FOR
 * ONLINE DPC"*. Marks and remarks are M5 outputs produced by a committee that has
 * not sat yet; showing them here would publish a provisional promotion decision to
 * the officer it concerns, before the sitting, from a screen with no signature on
 * it. That is a different feature and nobody has asked for it.
 */
class SelfInspectionViewService
{
    public function __construct(private readonly ObjectionDeadlineResolver $deadlines) {}

    /**
     * @return array<string, mixed>
     */
    public function present(DpcEvaluationRow $row): array
    {
        $row->loadMissing(['crGrades', 'loyaltyFlags', 'propertyFlags', 'reservationCategory', 'proposal.department', 'proposal.promotionToPost', 'proposal.promotionFromPost']);

        $proposal = $row->proposal;
        $deadline = $this->deadlines->resolve($proposal);

        return [
            'row' => $row,
            'proposal' => $proposal,
            'department' => $proposal->department,

            // The years actually recorded, unioned across the three per-year
            // children and sorted. Not a range: a Circulation DPC exists
            // precisely because an officer is missing CR years or property
            // returns, so a gap is real data and must render as a gap rather
            // than be filled in.
            'years' => $this->years($row),

            'cr' => $row->crGrades->pluck('grade', 'year')->all(),
            'loyalty' => $row->loyaltyFlags->pluck('flag', 'year')->all(),
            'property' => $row->propertyFlags->pluck('flag', 'year')->all(),

            'sitting_date' => $this->deadlines->sittingDate($proposal),
            'objection_deadline' => $deadline,
            'objection_deadline_passed' => $deadline !== null && $deadline->isPast(),
        ];
    }

    /**
     * Every year appearing in any of the three per-year records, ascending.
     *
     * @return list<int>
     */
    public function years(DpcEvaluationRow $row): array
    {
        $years = $row->crGrades->pluck('year')
            ->merge($row->loyaltyFlags->pluck('year'))
            ->merge($row->propertyFlags->pluck('year'))
            ->map(static fn ($year): int => (int) $year)
            ->unique()
            ->sort()
            ->values();

        /** @var list<int> $list */
        $list = $years->all();

        return $list;
    }

    /**
     * The date the officer sees in the notice, formatted the way S51 writes it —
     * *"दिनांक 25 जून 2026"*.
     *
     * Returns null when no deadline could be derived, and the notice then omits
     * the date rather than inventing one.
     */
    public function formattedDeadline(?Carbon $deadline): ?string
    {
        return $deadline?->translatedFormat('j F Y');
    }
}
