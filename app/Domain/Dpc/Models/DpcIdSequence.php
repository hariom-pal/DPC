<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Department\Models\Department;
use App\Domain\Shared\Services\IdentifierSequenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-department, per-year, **per-series** serial counter behind both the DPC
 * Number and the Pre-DPC Number.
 *
 * Internal to {@see IdentifierSequenceService} —
 * nothing else reads or writes it, and nothing else should: the counter is only
 * safe when it is read under the row lock that service takes.
 *
 * The `series` column arrived with change item 10, which added a second
 * identifier of exactly the same shape. The table keeps its original name; it is
 * referenced by a foreign key from nothing and renaming it would be churn.
 *
 * @property int $id
 * @property int $department_id
 * @property string $series
 * @property int $year
 * @property int $last_serial
 */
class DpcIdSequence extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'series',
        'year',
        'last_serial',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_serial' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
