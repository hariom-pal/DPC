<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Eligibility\Services\DpcRuleBook;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One version of the CR marks and qualifying scores (rules 3.4 and 3.5), from a
 * DPC year onwards, on the order that authorised it.
 *
 * Never edited: a change is a new version, so which scale a DPC was judged on stays
 * answerable. A DPC uses the latest version whose first year is not after its DPC
 * year ({@see DpcRuleBook}).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int $effective_from_year
 * @property array<string, int> $grade_marks
 * @property int $scoring_years
 * @property int $tribal_area_bonus
 * @property string $disqualifying_grade
 * @property array<string, string> $last_year_forbidden_grades
 * @property string $super_seat_grade
 * @property array<string, int> $qualifying_scores
 * @property string $order_number
 * @property Carbon $order_date
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $remarks
 * @property int|null $recorded_by
 * @property Carbon $recorded_at
 */
class CrScoringScale extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    protected $fillable = [
        'name', 'effective_from_year', 'grade_marks', 'scoring_years', 'tribal_area_bonus',
        'disqualifying_grade', 'last_year_forbidden_grades', 'super_seat_grade', 'qualifying_scores',
        'order_number', 'order_date', 'disk', 'path', 'original_name', 'size_bytes', 'sha256',
        'remarks', 'recorded_by', 'recorded_at',
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
        return [
            'effective_from_year' => 'integer',
            'grade_marks' => 'array',
            'scoring_years' => 'integer',
            'tribal_area_bonus' => 'integer',
            'last_year_forbidden_grades' => 'array',
            'qualifying_scores' => 'array',
            'order_date' => 'date',
            'size_bytes' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
