<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Department\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-department, per-year serial counter backing DPC ID generation
 * (`<DeptCode><Year><Serial>`, PPT S28/S32/S37). Internal to
 * DpcIdGeneratorService — not exposed elsewhere.
 *
 * @property int $id
 * @property int $department_id
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
