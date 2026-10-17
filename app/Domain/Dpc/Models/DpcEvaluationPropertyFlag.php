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
 * the department's decision on a return submitted after 31 January of the following
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
     * Rule 4.1: an IPR must be submitted by 31 January of the year after the one it
     * covers. Anything later needs the department's decision.
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
