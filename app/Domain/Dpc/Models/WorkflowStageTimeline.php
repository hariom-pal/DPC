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
 * @property string $on_expiry notify | cancel | forward (2026-11-17)
 * @property int $reminder_every_days
 * @property bool $remind_email
 * @property bool $remind_sms
 * @property int|null $updated_by
 */
class WorkflowStageTimeline extends Model
{
    public const NOTIFY = 'notify';

    public const CANCEL = 'cancel';

    public const FORWARD = 'forward';

    protected $fillable = ['stage', 'days', 'on_expiry', 'reminder_every_days', 'remind_email', 'remind_sms', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => DpcStage::class,
            'days' => 'integer',
            'reminder_every_days' => 'integer',
            'remind_email' => 'boolean',
            'remind_sms' => 'boolean',
        ];
    }

    /** The stage's settings, or the defaults where the Administrator has saved none. */
    public static function for(DpcStage $stage): self
    {
        return self::query()->where('stage', $stage->value)->first()
            ?? new self([
                'stage' => $stage->value,
                'days' => null,
                'on_expiry' => $stage === DpcStage::Secretary ? self::FORWARD : self::NOTIFY,
                'reminder_every_days' => 1,
                'remind_email' => true,
                'remind_sms' => false,
            ]);
    }

    public static function daysFor(DpcStage $stage): ?int
    {
        $days = self::query()->where('stage', $stage->value)->value('days');

        return $days === null ? null : (int) $days;
    }
}
