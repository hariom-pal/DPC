<?php

declare(strict_types=1);

namespace App\Domain\Committee\Models;

use App\Domain\Authentication\Models\User;
use App\Support\Concerns\Blameable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Who the CGPSC Chairman is, and was (change register of 2026-08-26, item 9 as
 * clarified).
 *
 * Item 9 requires every DPC committee to contain the CGPSC Chairman, added by the
 * system rather than by the Authorized Officer. That makes *"who is the CGPSC
 * Chairman"* a question the portal must be able to answer — and, for a committee
 * composed last year, *"who was the CGPSC Chairman then"*.
 *
 * The `chairman` **role** cannot answer either. Nothing stops two accounts holding
 * it, and a committee cannot have two CGPSC Chairmen; and a role grant carries no
 * tenure, so it can only ever say who holds the office *now*. A Chairman succeeded
 * in March must not retrospectively appear on February's committee.
 *
 * So this is an effective-dated chain. Appointing a successor closes the
 * incumbent's tenure and opens a new row; `active_flag` is `1` on the serving row
 * and NULL on every former one, and `unique(active_flag)` makes *at most one
 * serving Chairman* a fact the database holds rather than a rule a service is
 * trusted to remember.
 *
 * **The contact details are a snapshot.** `name`, `designation`, `email` and
 * `mobile` are held here rather than read off the user for the reason every signed
 * record in this portal copies what it states: a committee records who sat on it,
 * and editing an account must not rewrite an order already issued.
 *
 * **This is not a `Role::Chairman` grant and does not create one.** The two are
 * kept deliberately separate: this record says who holds the *office*, and the role
 * says which account may open the Commission's screens. An Administrator who
 * appoints a Chairman here is stating a fact about the Commission, not handing out
 * a permission — see `CgpscChairmanService::appoint()`, which says so at the point
 * it would have been tempting to do both.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property string $name
 * @property string|null $designation
 * @property string|null $email
 * @property string|null $mobile
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int|null $active_flag
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class CgpscChairman extends Model implements Auditable
{
    use AuditableTrait;
    use Blameable;
    use HasUuids;

    protected $table = 'cgpsc_chairmen';

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * The public identifier is the UUID (AD-8), including in the URL.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * `active_flag` is absent, and deliberately.
     *
     * Serving or not is decided by `appoint()` closing one tenure and opening the
     * next, under a transaction. A payload that could set the flag could produce
     * two serving Chairmen — or none — which is exactly what the unique index and
     * the service exist together to prevent.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'designation',
        'email',
        'mobile',
        'effective_from',
        'effective_to',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
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
     * The Chairman serving now.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeServing(Builder $query): Builder
    {
        return $query->whereNotNull('active_flag');
    }

    /**
     * The Chairman serving on a given date.
     *
     * Used when a committee is composed against a historical proposal date, so the
     * seat records who actually held the office then rather than who holds it now.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeServingOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->where('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }

    public function isServing(): bool
    {
        return $this->active_flag !== null;
    }

    /**
     * How the tenure reads on a screen — "from 01-04-2026" or a closed range.
     */
    public function tenureLabel(): string
    {
        $from = $this->effective_from->format('d-m-Y');

        return $this->effective_to === null
            ? __('admin.cgpsc_chairman.since', ['date' => $from])
            : sprintf('%s — %s', $from, $this->effective_to->format('d-m-Y'));
    }
}
