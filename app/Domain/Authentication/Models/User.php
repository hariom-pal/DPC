<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Models;

use App\Domain\Authentication\Services\ActiveRoleService;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Designation;
use App\Domain\Signature\Models\Signature;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
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
        'employee_number',
        'password',
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
     * The roles this account has actually been granted.
     *
     * **Queried, never read off the `roles` relation**, and that distinction is the
     * whole reason this method exists. Since the change register of 2026-08-26,
     * `ResolveActiveRole` constrains the loaded relation to the single role the
     * session is *acting as* — so `$user->roles` answers "what may this request do"
     * and this answers "what may this account switch to". Reading the relation here
     * would make the role switcher offer only the role already active, which is a
     * switcher that cannot switch.
     *
     * @return list<string>
     */
    public function grantedRoleNames(): array
    {
        /** @var list<string> $names */
        $names = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();

        return $names;
    }

    /**
     * Every role this account may act as.
     *
     * @return list<Role>
     */
    public function grantedRoles(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $name): ?Role => Role::tryFrom($name),
            $this->grantedRoleNames(),
        )));
    }

    /**
     * Whether this account holds a role at all, regardless of what it is acting as.
     *
     * For the handful of callers that need the grant rather than the capacity —
     * user management listings, and the switcher. Authorisation must **not** use
     * this: `hasRole()` is the acting-capacity question and is the one every policy
     * asks.
     */
    public function holdsRole(Role $role): bool
    {
        return in_array($role->value, $this->grantedRoleNames(), true);
    }

    /**
     * The role this session is acting as (change register of 2026-08-26).
     */
    public function activeRole(): ?Role
    {
        return app(ActiveRoleService::class)->resolve($this);
    }

    /**
     * Every posting this account has ever held, newest first.
     *
     * @return HasMany<UserDepartmentAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserDepartmentAssignment::class)
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }

    /**
     * The one posting in force now, if any.
     *
     * A relation rather than an accessor so callers can eager-load it —
     * `with('activeAssignment.department')` — which matters because `department`
     * and `department_id` below both read through it and would otherwise be an
     * N+1 on every user listing.
     *
     * @return HasOne<UserDepartmentAssignment, $this>
     */
    public function activeAssignment(): HasOne
    {
        return $this->hasOne(UserDepartmentAssignment::class)->whereNotNull('active_flag');
    }

    /**
     * The department this account is *currently* posted to, or null.
     *
     * **This was a `belongsTo` until 2026-08-24 and is now derived** (change item
     * 2): an officer does not belong to a department, they are assigned to one,
     * and the assignment is dated. Reading it stays `$user->department`, so every
     * call site is unchanged; what changed is that the answer is now a fact with
     * a date attached rather than a column that gets overwritten on transfer.
     *
     * Not a relation, so it cannot be used in `with()` — load
     * `activeAssignment.department` instead, which is what this reads.
     */
    public function getDepartmentAttribute(): ?Department
    {
        return $this->activeAssignment?->department;
    }

    /**
     * The current department's id, or null for a Commission account.
     *
     * Kept as an attribute because roughly twenty policies, requests, services
     * and dashboards ask exactly this question, and the shape of the question did
     * not change — only where the answer comes from.
     */
    public function getDepartmentIdAttribute(): ?int
    {
        return $this->activeAssignment?->department_id;
    }

    /**
     * The role the current posting grants, or null.
     *
     * For a Commission account this is null even though the account holds a
     * Spatie role: Commission roles are not departmental and so have no
     * assignment. Use `hasRole()` to ask about permissions and this to ask about
     * the posting.
     */
    public function getAssignedRoleAttribute(): ?Role
    {
        return $this->activeAssignment?->role;
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
        ];
    }
}
