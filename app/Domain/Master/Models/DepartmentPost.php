<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Concerns\IsMaster;
use App\Domain\Master\Services\PostAttributeResolver;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A post as it exists in one department — the Department Post Mapping of change
 * item 4, and what the system called a "Cadre" until 2026-08-24.
 *
 * **The department is part of the post's identity, not a label on it.** Education,
 * Forest and Finance may each have a "Secretary"; those are three posts, with
 * three pay scales and three promotion ladders, and `unique(department_id, name)`
 * is what says so. Nothing in the application may look a post up by name alone.
 *
 * The four attributes below travel *with* the post rather than being re-entered
 * on each proposal, which is what makes item 5's auto-population possible:
 * choosing a Promotion From Post and a Promotion To Post is enough to determine
 * the Pay Scale, the Class and the Promotion Duration. See
 * {@see PostAttributeResolver} for the one place
 * that derivation happens.
 *
 * @property int $id
 * @property int $department_id
 * @property string $name
 * @property ServiceClass $class_level
 * @property int|null $pay_scale_id
 * @property int|null $promotion_duration_years
 * @property int|null $sanctioned_strength
 * @property-read Collection<int, DepartmentPostOrder> $orders
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property int|null $active_flag
 * @property MasterStatus $status
 */
class DepartmentPost extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'name',
        'class_level',
        'pay_scale_id',
        'promotion_duration_years',

        /*
         | The number of posts the Government has sanctioned for this mapping
         | (change register of 2026-08-27, item 1).
         |
         | The Vacancy Engine reads it here rather than from the proposal, because a
         | sanctioned strength is what a Government Order says and this is where
         | those are kept. Effective-dated for free: a change of strength is a new
         | order and therefore a new mapping row.
         */
        'sanctioned_strength',

        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_level' => ServiceClass::class,
            'promotion_duration_years' => 'integer',
            'sanctioned_strength' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * Keep `active_flag` in step with `status`, always.
     *
     * The flag exists so `unique(department_id, name, active_flag)` can mean *at
     * most one mapping in force*; it carries no information `status` does not
     * already have. Deriving it here rather than asking every caller to set it is
     * the same reasoning as everywhere else in this codebase: a field that has to
     * be remembered at each call site is a field that will be forgotten at one of
     * them — and the one that forgets produces a mapping the pickers cannot see.
     *
     * Re-activating a superseded mapping while another is in force is refused by
     * the unique index, which is the correct answer: two mappings cannot both be
     * in force for one post.
     */
    protected static function booted(): void
    {
        static::saving(function (self $post): void {
            $post->active_flag = $post->status === MasterStatus::Active ? 1 : null;
        });
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<PayScale, $this>
     */
    public function payScale(): BelongsTo
    {
        return $this->belongsTo(PayScale::class);
    }

    /**
     * Every Government Order bearing on this mapping (change register of
     * 2026-08-26, item 10).
     *
     * Ordered oldest first, by the date on the instrument rather than by when it
     * was keyed in: a corrigendum entered before the order it corrects still reads
     * after it, which is the sequence a person needs.
     *
     * @return HasMany<DepartmentPostOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(DepartmentPostOrder::class)
            ->orderBy('government_order_date')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * The order that authorised this mapping — the earliest one on it.
     *
     * **The earliest rather than the latest**, because a mapping is created by an
     * order and then amended by later ones. "Which order established this pay
     * scale" is answered by the first; the rest are what happened afterwards.
     *
     * @return HasOne<DepartmentPostOrder, $this>
     */
    public function originatingOrder(): HasOne
    {
        return $this->hasOne(DepartmentPostOrder::class)
            ->orderBy('government_order_date')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * The mappings a proposal may actually be raised under.
     *
     * `whereNotNull('active_flag')` rather than `where('active_flag', 1)`: the
     * column is `1` or NULL by design, and writing the comparison the other way
     * hides that from the next reader.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNotNull('active_flag')->where('status', MasterStatus::Active->value);
    }

    /**
     * Superseded mappings, which exist so an old DPC can still be audited against
     * the order it ran under.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->whereNull('active_flag');
    }

    public function isInForce(): bool
    {
        return $this->active_flag !== null;
    }

    /**
     * How this mapping's authority reads on a screen.
     *
     * Says "not recorded" rather than showing an empty cell: a mapping registered
     * before orders were captured has none, and a blank space beside the words
     * *Government Order* reads as a bug rather than as a fact.
     *
     * **Names every order, not just the first** (change register of 2026-08-26,
     * item 10). A mapping amended twice is governed by three instruments, and a
     * label that showed only the originating one would understate the authority a
     * reader is being asked to rely on.
     */
    public function governmentOrderLabel(): string
    {
        $orders = $this->relationLoaded('orders')
            ? $this->orders
            : $this->orders()->get();

        if ($orders->isEmpty()) {
            return (string) __('admin.department_posts.not_recorded');
        }

        return $orders
            ->map(static fn (DepartmentPostOrder $order): string => $order->citation())
            ->implode(', ');
    }
}
