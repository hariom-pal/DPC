<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
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
     * The validation rule for an officer's designation (2026-11-04): an active
     * designation of this master, never free text and never one withdrawn. The
     * officer's current designation stays acceptable when unchanged, so an account
     * can still be saved after its designation is withdrawn from the master.
     */
    public static function masterRule(?int $current = null): Exists
    {
        return Rule::exists('designations', 'id')->where(function ($query) use ($current): void {
            $query->where(fn ($active) => $active->where('status', MasterStatus::Active->value)->whereNull('deleted_at'));

            if ($current !== null) {
                $query->orWhere('id', $current);
            }
        });
    }

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
