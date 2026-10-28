<?php

declare(strict_types=1);

namespace App\Domain\Department\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcIdSequence;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\District;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Enums\DepartmentStatus;
use App\Support\Concerns\Blameable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
     * The accounts *currently* posted to this department.
     *
     * **A `hasManyThrough` since 2026-08-24, not a `hasMany`** (change item 2).
     * `users.department_id` is gone: an officer is assigned to a department for a
     * period, so "this department's users" is a question about live assignments
     * rather than about a column on the account. The `whereNotNull` is what makes
     * it *currently* — without it a transferred officer would still appear here
     * for ever.
     *
     * @return HasManyThrough<User, UserDepartmentAssignment, $this>
     */
    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(
            User::class,
            UserDepartmentAssignment::class,
            'department_id',
            'id',
            'id',
            'user_id',
        )->whereNotNull('user_department_assignments.active_flag');
    }

    /**
     * Every posting ever made to this department, current or superseded.
     *
     * @return HasMany<UserDepartmentAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserDepartmentAssignment::class);
    }

    /**
     * @return BelongsTo<District, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * Department registration shows the address and contact e-mail from the
     * Administrator's master read-only (business decision, 2026-10-22), so a
     * department can be registered only once the master holds all four.
     */
    public function hasRegistrationDetails(): bool
    {
        return $this->district_id !== null
            && filled($this->place)
            && filled($this->pin)
            && filled($this->email);
    }

    /**
     * @return HasMany<DepartmentPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(DepartmentPost::class);
    }

    /**
     * @return HasMany<DpcProposal, $this>
     */
    public function dpcProposals(): HasMany
    {
        return $this->hasMany(DpcProposal::class);
    }

    /**
     * Issued DPC ID counters. Their existence is what makes `code` immutable:
     * the code is the prefix of every ID already handed out.
     *
     * @return HasMany<DpcIdSequence, $this>
     */
    public function dpcIdSequences(): HasMany
    {
        return $this->hasMany(DpcIdSequence::class);
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
