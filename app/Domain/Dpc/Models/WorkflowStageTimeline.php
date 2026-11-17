<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Dpc\Enums\DpcStage;
use Illuminate\Database\Eloquent\Model;

/**
 * The days the CGPSC Administrator allows one stage of a DPC (MoM of 16 November
 * 2026, point 6). `days` null: no deadline for the stage.
 *
 * @property int $id
 * @property DpcStage $stage
 * @property int|null $days
 * @property int|null $updated_by
 */
class WorkflowStageTimeline extends Model
{
    protected $fillable = ['stage', 'days', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['stage' => DpcStage::class, 'days' => 'integer'];
    }

    public static function daysFor(DpcStage $stage): ?int
    {
        $days = self::query()->where('stage', $stage->value)->value('days');

        return $days === null ? null : (int) $days;
    }
}
