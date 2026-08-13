<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared behaviour for the two per-year Y/N columns on the Evaluation /
 * Tabulation Chart (PPT S18) — Loyalty and Immovable Property Details. They
 * are structurally identical (row, year, flag) and live in separate tables
 * per the approved Phase 5 design, so the shape is defined once here.
 *
 * `flag` is nullable on purpose: "recorded as No" and "not recorded at all"
 * are different facts, and the Circulation-DPC rule turns on a missing
 * immovable-property return. Interpreting that difference is M5's job.
 *
 * @property int $id
 * @property int $dpc_evaluation_row_id
 * @property int $year
 * @property bool|null $flag
 */
abstract class DpcEvaluationYearFlag extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_evaluation_row_id',
        'year',
        'flag',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'flag' => 'boolean',
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
