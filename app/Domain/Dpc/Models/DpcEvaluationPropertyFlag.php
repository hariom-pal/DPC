<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use Illuminate\Support\Carbon;

/**
 * One year's Immovable Property Return (IPR) for one officer.
 *
 * `flag` is what the engine reads: whether the return counts as filed for the year.
 * The other columns are the rule 4.1 details the Authorized Officer records on the
 * employee's details screen — the submission date, the date of next increment printed
 * on the return (page 4: the field that tells two uploads of the same IPR apart), and
 * the department's decision on a return submitted after 31 January of the next
 * year.
 *
 * @property int $id
 * @property int $dpc_evaluation_row_id
 * @property int $year
 * @property bool|null $flag
 * @property Carbon|null $submitted_on
 * @property Carbon|null $next_increment_date
 * @property string|null $late_decision
 * @property string|null $late_decision_remarks
 */
class DpcEvaluationPropertyFlag extends DpcEvaluationYearFlag
{
    public const LATE_ACCEPTED = 'accepted';

    public const LATE_REJECTED = 'rejected';

    protected $table = 'dpc_evaluation_property_flags';

    protected $fillable = [
        'dpc_evaluation_row_id',
        'year',
        'flag',
        'submitted_on',
        'next_increment_date',
        'late_decision',
        'late_decision_remarks',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'flag' => 'boolean',
            'submitted_on' => 'date',
            'next_increment_date' => 'date',
        ];
    }

    /**
     * The only dates of next increment a year's Property File can carry (2026-11-16):
     * 1 January and 1 July of the next year — the 2025 file, 01-01-2026 or 01-07-2026.
     *
     * @return list<Carbon>
     */
    public static function incrementDatesFor(int $year): array
    {
        return [Carbon::create($year + 1, 1, 1)->startOfDay(), Carbon::create($year + 1, 7, 1)->startOfDay()];
    }

    /**
     * Rule 4.1: an IPR is due by 31 January of the next year — the 2025 return by
     * 31 January 2026, the 2024 return by 31 January 2025 (business decision,
     * 2026-11-16). Anything later needs the department's decision.
     */
    public static function dueDateFor(int $year): Carbon
    {
        return Carbon::create($year + 1, 1, 31)->startOfDay();
    }

    public function isLate(): bool
    {
        return $this->submitted_on !== null && $this->submitted_on->gt(self::dueDateFor($this->year));
    }
}
