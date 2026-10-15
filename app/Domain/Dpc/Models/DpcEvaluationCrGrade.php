<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One year's CR grade for an evaluation row (PPT S18, per-year layout per
 * decision Q4) — M4 Phase 5.
 *
 * `grade` is nullable: a Circulation DPC exists precisely because an officer
 * is missing CR years, so "this year is on the chart but no grade was
 * recorded" must be representable. The grade -> mark conversion is M5's.
 *
 * @property int $id
 * @property int $dpc_evaluation_row_id
 * @property int $year
 * @property string|null $grade
 */
class DpcEvaluationCrGrade extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_evaluation_row_id',
        'year',
        'grade',

        /*
         | Whether the officer served in a tribal area during *this* CR year
         | (change register of 2026-08-27, item 18).
         |
         | Recorded so the benefit is attributable to its own year and cannot read as
         | applying to another. **It does not feed the score**: `ScoringService`
         | applies PPT §5.2's single flat bonus from the officer-level total, and item
         | 21 forbids modifying that formula. BD-36.
         */
        'tribal_area',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'tribal_area' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<DpcEvaluationRow, $this>
     */
    public function row(): BelongsTo
    {
        return $this->belongsTo(DpcEvaluationRow::class, 'dpc_evaluation_row_id');
    }
}
