<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Models;

use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Enums\RemarkCode;
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
     * The candidate's final status — one of item 24's four (change register of
     * 2026-08-27).
     *
     * Read from the **stored verdict**, not recomputed and not inferred from
     * `is_eligible`. Two reasons, and both matter:
     *
     * - `is_eligible` is a boolean, and item 24 has four values. Inferring the status
     *   from it would print "Not Eligible" against a CIR candidate — whose promotion
     *   is *held pending a record*, not refused. That is a materially different and
     *   more damaging statement to put on a government promotion list.
     * - A result is evidence. Recomputing the status at display time would let a
     *   screen disagree with the run it is showing, and the whole point of storing
     *   the verdict is that the calculation is reproducible from what was saved.
     *
     * Falls back to the boolean only for rows written before this register, which
     * carry no `remark` in their verdict. Those really do have just two states.
     */
    public function status(): RemarkCode
    {
        $stored = $this->verdict['remark'] ?? null;

        if (is_string($stored)) {
            $code = RemarkCode::tryFrom($stored);

            if ($code !== null) {
                return $code;
            }
        }

        return $this->is_eligible ? RemarkCode::Eligible : RemarkCode::NotEligible;
    }

    /**
     * The grounds recorded against this result, gate reasons first.
     *
     * Empty for a clean pass. Kept as a method rather than left to each view to dig
     * into the verdict array, because a rejection an officer cannot read the ground
     * for is indefensible if the promotion is challenged.
     *
     * @return list<string>
     */
    public function failureReasons(): array
    {
        $reasons = $this->verdict['failure_reasons'] ?? [];

        return is_array($reasons) ? array_values(array_filter($reasons, 'is_string')) : [];
    }

    /**
     * The justified missing records behind a CIR (item 19).
     *
     * "CIR" on its own does not say what is awaited, and the officer is entitled to
     * know which record is holding their promotion.
     *
     * @return list<string>
     */
    public function gateCitations(): array
    {
        $citations = $this->verdict['gate_citations'] ?? [];

        return is_array($citations) ? array_values(array_filter($citations, 'is_string')) : [];
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
