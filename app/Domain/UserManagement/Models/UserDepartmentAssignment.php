<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Enums\Role;
use App\Support\Concerns\Blameable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One posting: this user, in this department, in this role, between these dates
 * (change item 2).
 *
 * **The row is the officer's posting, not the officer.** An officer transferred
 * from Higher Education to Forest keeps their login and gains a second row; the
 * first is deactivated, dated out, and kept. That is what makes the history
 * answerable, and it is why nothing here is ever deleted.
 *
 * **`active_flag` is `1` or `NULL`, never `0`.** The migration explains the
 * mechanism in full; the short version is that `unique(user_id, active_flag)`
 * only means "at most one active assignment" because NULLs do not collide. Set
 * it through {@see activate()} and {@see deactivate()} rather than by hand, and
 * go through `DepartmentAssignmentService` rather than either of those, because
 * activating an assignment must also re-project the Spatie role grant.
 *
 * @property int $id
 * @property int $user_id
 * @property int $department_id
 * @property Role $role
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int|null $active_flag
 * @property string|null $remarks
 */
class UserDepartmentAssignment extends Model implements Auditable
{
    use AuditableTrait;
    use Blameable;

    public const KIND_PRIMARY = 'primary';

    public const KIND_ADDITIONAL_CHARGE = 'additional_charge';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'department_id',
        'role',
        'effective_from',
        'effective_to',
        'active_flag',
        'kind',
        'primary_flag',
        'approved_by',
        'approved_at',
        'remarks',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'active_flag' => 'integer',
            'primary_flag' => 'integer',
            'approved_at' => 'datetime',
        ];
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
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function isActive(): bool
    {
        return $this->active_flag !== null;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('active_flag');
    }

    /**
     * Active and within its dates today (Phase 6, 2026-10-27): an assignment whose
     * end date has passed confers nothing, even before the scheduler closes it.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->whereNotNull('active_flag')
            ->whereDate('effective_from', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today));
    }

    public function isAdditionalCharge(): bool
    {
        return $this->kind === self::KIND_ADDITIONAL_CHARGE;
    }

    /**
     * Mark this the user's current posting.
     *
     * Does **not** deactivate whatever was active before, and does not touch the
     * Spatie grant — both are `DepartmentAssignmentService`'s job, under a lock,
     * because doing them separately is how a user ends up with two active
     * assignments or a role that outlives the posting that granted it. Saving
     * this alone against an existing active row will be refused by
     * `uda_user_active_unique`, which is the point.
     */
    public function activate(): void
    {
        $this->active_flag = 1;
    }

    /**
     * Retire this posting as of `$on`, keeping the row.
     *
     * `effective_to` is only written if it is not already set: a posting that was
     * dated out when it was created (a fixed-term deputation, say) keeps the date
     * the administrator chose rather than having it silently moved to today.
     */
    public function deactivate(Carbon $on): void
    {
        $this->active_flag = null;
        $this->primary_flag = null;
        $this->effective_to ??= $on;
    }
}
