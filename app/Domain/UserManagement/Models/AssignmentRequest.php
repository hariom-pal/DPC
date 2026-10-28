<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A transfer or additional-charge request (Phases 7–8, 2026-10-27).
 *
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property int $user_id
 * @property int|null $from_department_id
 * @property int $to_department_id
 * @property Role $role
 * @property string $order_number
 * @property Carbon $order_date
 * @property string|null $document_disk
 * @property string|null $document_path
 * @property string|null $document_name
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string|null $reason
 * @property string $status
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon|null $processed_at
 * @property string|null $failure_reason
 * @property-read User $user
 * @property-read Department|null $fromDepartment
 * @property-read Department $toDepartment
 * @property-read User|null $requestedBy
 * @property-read User|null $decidedBy
 */
class AssignmentRequest extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_ADDITIONAL_CHARGE = 'additional_charge';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EFFECTIVE = 'effective';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type', 'user_id', 'from_department_id', 'to_department_id', 'role', 'order_number', 'order_date',
        'document_disk', 'document_path', 'document_name', 'document_sha256', 'effective_from', 'effective_to',
        'reason', 'status', 'requested_by',
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
            'role' => Role::class,
            'order_date' => 'date',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'decided_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Awaiting a decision, or approved and not yet in effect.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_APPROVED]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
