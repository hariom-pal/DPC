<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $code
 * @property MasterStatus $status
 */
class Designation extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
    ];

    /**
     * Active designations a department has mapped (2026-10-30): a Post Mapping
     * in force names its post from this master, so an officer's designation is
     * chosen among the department's own posts.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeMappedIn(Builder $query, int $departmentId): Builder
    {
        return $query->where('status', MasterStatus::Active->value)
            ->whereIn('name', DepartmentPost::query()->inForce()->where('department_id', $departmentId)->select('name'))
            ->orderBy('name');
    }
}
