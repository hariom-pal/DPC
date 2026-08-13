<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\ValueObjects;

use App\Domain\Dpc\Models\DpcEvaluationRow;

/**
 * The engine's input: one officer's raw, already-recorded data — M5 Phase 1.
 *
 * A plain value object rather than an Eloquent model, for two reasons. It lets
 * every rule be unit-tested and golden-tested without a database, and it keeps
 * the engine reading from a snapshot: M4 stores proposal-time snapshots
 * precisely so a historical calculation stays reproducible, and an engine
 * holding live models could quietly recompute against changed data.
 *
 * `fromEvaluationRow()` is the only bridge to M4, which stays frozen and
 * unaware of this milestone.
 */
final class OfficerRecord
{
    /**
     * @param  array<int, string|null>  $crGrades  year => grade (null = year on the chart, no grade recorded)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $crGrades,
        public readonly ?int $tribalAreaServiceYears = null,
        public readonly bool $isReservedCategory = false,
        public readonly ?string $reservationCategoryCode = null,
        public readonly ?int $serialNumber = null,
    ) {}

    public static function fromEvaluationRow(DpcEvaluationRow $row): self
    {
        $row->loadMissing('crGrades', 'reservationCategory');

        $grades = [];
        foreach ($row->crGrades as $crGrade) {
            $grades[$crGrade->year] = $crGrade->grade;
        }

        return new self(
            name: $row->name,
            crGrades: $grades,
            tribalAreaServiceYears: $row->tribal_area_service_years,
            isReservedCategory: (bool) $row->reservationCategory->is_reserved,
            reservationCategoryCode: $row->reservationCategory->code,
            serialNumber: $row->sn,
        );
    }

    /**
     * §5.2 — the bonus turns on having served in a tribal area at all, not on
     * how long.
     */
    public function hasTribalAreaService(): bool
    {
        return ($this->tribalAreaServiceYears ?? 0) > 0;
    }
}
