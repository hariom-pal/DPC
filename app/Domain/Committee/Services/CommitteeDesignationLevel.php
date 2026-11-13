<?php

declare(strict_types=1);

namespace App\Domain\Committee\Services;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\PayScale;
use App\Enums\MasterStatus;
use Illuminate\Support\Collection;

/**
 * A committee member's designation is at the level of the post from which promotion
 * is made, above it, or at most one level below (MoM of 12 November 2026, point 4).
 *
 * **The level** is read from the Department Post mapping of that designation's name —
 * the class and pay scale the department mapped, the DPC's own department first —
 * and compared with the feeder post's:
 * - both have a pay scale: the member's may be at most one pay scale below the
 *   feeder's, pay scales ordered by their minimum pay;
 * - otherwise by class: at most one class below (Class I the highest).
 *
 * A designation no mapping describes (the CGPSC's seats, a GAD Deputy Secretary)
 * has no level to compare and stays selectable.
 */
class CommitteeDesignationLevel
{
    private const CLASS_RANK = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4];

    /**
     * The designations a member of this DPC's committee may hold.
     *
     * @param  Collection<int, Designation>  $designations
     * @return Collection<int, Designation>
     */
    public function selectable(DpcProposal $proposal, Collection $designations): Collection
    {
        return $designations->filter(fn (Designation $designation): bool => $this->allows($proposal, $designation->name))->values();
    }

    public function allows(DpcProposal $proposal, ?string $designation): bool
    {
        $feeder = $proposal->promotionFromPost;
        $member = $designation === null || trim($designation) === '' ? null : $this->postFor($proposal, $designation);

        if ($feeder === null || $member === null) {
            return true;
        }

        $steps = $this->payScaleSteps();
        $feederStep = $feeder->pay_scale_id !== null ? ($steps[$feeder->pay_scale_id] ?? null) : null;
        $memberStep = $member->pay_scale_id !== null ? ($steps[$member->pay_scale_id] ?? null) : null;

        if ($feederStep !== null && $memberStep !== null) {
            return $memberStep >= $feederStep - 1;
        }

        $feederClass = self::CLASS_RANK[(string) $feeder->class_level] ?? null;
        $memberClass = self::CLASS_RANK[(string) $member->class_level] ?? null;

        return $feederClass === null || $memberClass === null || $memberClass <= $feederClass + 1;
    }

    /** The mapping in force that describes this designation: the DPC's department first. */
    private function postFor(DpcProposal $proposal, string $designation): ?DepartmentPost
    {
        return DepartmentPost::query()->inForce()
            ->where('name', $designation)
            ->orderByRaw('case when department_id = ? then 0 else 1 end', [$proposal->department_id])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Each active pay scale's step, lowest pay first.
     *
     * @return array<int, int> pay scale id => step
     */
    private function payScaleSteps(): array
    {
        return PayScale::query()->where('status', MasterStatus::Active->value)
            ->orderBy('minimum_pay')->orderBy('id')
            ->pluck('id')
            ->flip()
            ->map(fn ($step): int => (int) $step)
            ->all();
    }
}
