<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An Eligible CR Grade: a numeric value a CR may carry, valid from `effective_from`
 * to `effective_to` (both inclusive; open-ended while `effective_to` is null).
 *
 * A grade is never edited or deleted once in force: it is deactivated by giving it an
 * end date. Bringing a value back is a new row from a new date, so every window it was
 * valid for stays on record.
 *
 * @property int $id
 * @property int $value
 * @property string|null $grade_code
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string|null $remarks
 * @property string|null $deactivation_reason
 * @property int|null $created_by
 * @property int|null $deactivated_by
 * @property Carbon|null $deactivated_at
 */
class CrGradeValue extends Model implements Auditable
{
    use AuditableTrait;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'value',
        'grade_code',
        'effective_from',
        'effective_to',
        'remarks',
        'deactivation_reason',
        'created_by',
        'deactivated_by',
        'deactivated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * Grades valid on a date.
     *
     * @param  Builder<self>  $query
     */
    public function scopeValidOn(Builder $query, Carbon $date): void
    {
        $day = $date->toDateString();

        $query->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day));
    }

    public function isValidOn(Carbon $date): bool
    {
        return $this->effective_from->lte($date)
            && ($this->effective_to === null || $this->effective_to->gte($date->copy()->startOfDay()));
    }

    /**
     * Active, upcoming (starts later) or inactive (its window has ended).
     */
    public function statusOn(Carbon $date): string
    {
        return match (true) {
            $this->effective_from->gt($date) => self::STATUS_UPCOMING,
            $this->effective_to !== null && $this->effective_to->lt($date->copy()->startOfDay()) => self::STATUS_INACTIVE,
            default => self::STATUS_ACTIVE,
        };
    }
}
