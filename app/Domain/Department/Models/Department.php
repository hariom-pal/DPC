<?php

declare(strict_types=1);

namespace App\Domain\Department\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\District;
use App\Enums\DepartmentStatus;
use App\Support\Concerns\Blameable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $district_id
 * @property string|null $place
 * @property string|null $pin
 * @property string|null $email
 * @property DepartmentStatus $status
 * @property Carbon|null $registered_at
 */
class Department extends Model implements Auditable
{
    use AuditableTrait;
    use Blameable;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'district_id',
        'place',
        'pin',
        'email',
        'status',
        'registered_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return BelongsTo<District, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DepartmentStatus::class,
            'registered_at' => 'datetime',
        ];
    }
}
