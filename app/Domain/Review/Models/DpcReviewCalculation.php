<?php

declare(strict_types=1);

namespace App\Domain\Review\Models;

use App\Domain\Eligibility\Models\DpcCalculationRun;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * Provenance of one Review calculation — M8 Phase 3.
 *
 * Records which rules a Review ran under and which original calculation those
 * rules came from (architect Directives 1 and 2). It exists as an M8 table
 * because `dpc_calculation_runs` belongs to engine-frozen M5.
 *
 * **Immutable.** A provenance record that could be edited would evidence
 * nothing about the calculation it describes.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_review_link_id
 * @property int $dpc_calculation_run_id
 * @property string $run_uuid
 * @property string $configuration_snapshot_uuid
 * @property string|null $original_calculation_run_uuid
 * @property string|null $rule_version
 * @property string|null $calculation_engine_version
 * @property Carbon $calculated_at
 */
class DpcReviewCalculation extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_review_link_id',
        'dpc_calculation_run_id',
        'run_uuid',
        'configuration_snapshot_uuid',
        'original_calculation_run_uuid',
        'rule_version',
        'calculation_engine_version',
        'calculated_at',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['calculated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            throw new RuntimeException('A calculation provenance record cannot be edited.');
        });

        static::deleting(function (self $record): void {
            throw new RuntimeException('Calculation provenance records are permanent and cannot be deleted.');
        });
    }

    /**
     * @return list<string>
     */
    public function generateTags(): array
    {
        return [$this->uuid, $this->configuration_snapshot_uuid];
    }

    /**
     * @return BelongsTo<DpcReviewLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(DpcReviewLink::class, 'dpc_review_link_id');
    }

    /**
     * @return BelongsTo<DpcCalculationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DpcCalculationRun::class, 'dpc_calculation_run_id');
    }
}
