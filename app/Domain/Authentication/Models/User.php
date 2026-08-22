<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Models;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Designation;
use App\Domain\Signature\Models\Signature;
use App\Enums\UserLevel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements Auditable
{
    use HasUuids;

    /**
     * A stable public identity for the user, added for timeline actor
     * snapshots (architect directive).
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * The public identifier is the UUID (AD-8), and from 2026-08-22 that
     * includes the URL.
     *
     * The column arrived in M6 for timeline actor snapshots, and the note here
     * used to say the route key was left as `id` "so no existing relation or URL
     * changes" — which was true and also left `/admin/users/7` in the User
     * Management screens. `id` remains the primary key, so `created_by`,
     * `approved_by`, `prepared_by` and every other actor reference are unchanged;
     * only the four `/admin/users/{user}` URLs now carry a UUID.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    use AuditableTrait;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    /**
     * The model lives outside App\Models, so bind its factory explicitly.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'designation_id',
        'email',
        'mobile',
        'password',
        'department_id',
        'level',
        'is_active',
        'must_change_password',
        'email_verified_at',
        'mobile_verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The department this account belongs to (null for CGPSC roles).
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * @return HasMany<Signature, $this>
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class);
    }

    /**
     * Both channels (email + mobile) verified per the registration policy.
     */
    public function isFullyVerified(): bool
    {
        return $this->email_verified_at !== null && $this->mobile_verified_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'level' => UserLevel::class,
        ];
    }
}
