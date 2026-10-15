<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $department_id
 * @property int $post_id
 * @property string $name
 * @property int $size
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property MasterStatus $status
 */
class ReservationRoster extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'post_id',
        'name',
        'size',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class);
    }

    /**
     * @return HasMany<ReservationRosterPoint, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(ReservationRosterPoint::class)->orderBy('point_no');
    }
}
