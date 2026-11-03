<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Post Mapping change the Authorized Officer asked for, awaiting the Department
 * Admin's approval (UAT, 2026-11-03). Written and decided only through
 * `DepartmentPostChangeService`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $department_id
 * @property int|null $department_post_id
 * @property string $action
 * @property array<string, mixed> $payload
 * @property list<array{field: string, disk: string, path: string, original_name: string, size_bytes: int, sha256: string}>|null $documents
 * @property string $status
 * @property int $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $remarks
 * @property int|null $result_post_id
 * @property Carbon|null $created_at
 * @property-read Department|null $department
 * @property-read DepartmentPost|null $post
 * @property-read DepartmentPost|null $result
 * @property-read User|null $requester
 * @property-read User|null $decider
 */
class DepartmentPostChange extends Model
{
    use HasUuids;

    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_SUPERSEDE = 'supersede';

    public const ACTION_DELETE = 'delete';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'department_id', 'department_post_id', 'action', 'payload', 'documents', 'status',
        'requested_by', 'decided_by', 'decided_at', 'remarks', 'result_post_id',
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
            'payload' => 'array',
            'documents' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
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
        return $this->belongsTo(DepartmentPost::class, 'department_post_id')->withTrashed();
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'result_post_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
