<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Models;

use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Eligibility\Enums\ListType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One officer's outcome within a calculation run (§5.7) — M5 Phase 3.
 *
 * **Immutable from the moment it is written.** No role, including an
 * administrator, may edit a calculation result (architect directive), enforced
 * at the model layer so the guarantee survives any caller. A result that could
 * be edited after the Commission acted on it would be worthless as evidence,
 * and worse than worthless if anyone believed it.
 *
 * Every officer on the chart gets a row, including those on neither list, so
 * an absent name is explained rather than silently missing.
 *
 * The officer's identity is snapshotted here rather than only joined, so the
 * result stays readable if the underlying proposal data ever changes.
 *
 * @property int $id
 * @property int $dpc_calculation_run_id
 * @property int|null $dpc_evaluation_row_id
 * @property int $sn
 * @property string $officer_name
 * @property string|null $employee_number
 * @property string $reservation_category_code
 * @property array<string, mixed> $score_breakdown
 * @property array<string, mixed> $verdict
 * @property array<string, mixed> $super_seat
 * @property bool $is_eligible
 * @property bool $is_super_seat
 * @property bool $in_zone
 * @property string $remark
 * @property ListType $list_type
 * @property int|null $list_position
 * @property int|null $rank
 */
class DpcCalculationResult extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_calculation_run_id',
        'dpc_evaluation_row_id',
        'sn',
        'officer_name',
        'employee_number',
        'reservation_category_code',
        'score_breakdown',
        'verdict',
        'super_seat',
        'is_eligible',
        'is_super_seat',
        'in_zone',
        'remark',
        'list_type',
        'list_position',
        'rank',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score_breakdown' => 'array',
            'verdict' => 'array',
            'super_seat' => 'array',
            'is_eligible' => 'boolean',
            'is_super_seat' => 'boolean',
            'in_zone' => 'boolean',
            'list_type' => ListType::class,
            'sn' => 'integer',
            'list_position' => 'integer',
            'rank' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $result): void {
            throw new RuntimeException(
                'Calculation results are immutable. No role may edit a calculation result — run the calculation '
                .'again to produce a new run.'
            );
        });

        static::deleting(function (self $result): void {
            // Cascade from a deleted run is impossible: runs cannot be deleted.
            throw new RuntimeException('Calculation results are permanent evidence and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<DpcCalculationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DpcCalculationRun::class, 'dpc_calculation_run_id');
    }

    /**
     * @return BelongsTo<DpcEvaluationRow, $this>
     */
    public function evaluationRow(): BelongsTo
    {
        return $this->belongsTo(DpcEvaluationRow::class, 'dpc_evaluation_row_id');
    }
}
