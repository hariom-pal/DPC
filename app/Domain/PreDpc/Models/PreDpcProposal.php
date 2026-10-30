<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Models;

use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\PayScale;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\Signature\Models\Signature;
use App\Enums\ServiceClass;
use App\Support\Concerns\Blameable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One **promotion exercise** sanctioned for a department (PPT S7–S11).
 *
 * **Reshaped on 2026-08-24 from a list of posts into a single post pair** (change
 * items 8–11). A Pre-DPC now says: *in this department, for DPC Year 2026,
 * officers may be promoted from this post into that post, on these terms.* See
 * the reshaping migration for why the three rulings only fit together this way,
 * and for what happens to a post that is fed from more than one place.
 *
 * **The identity of an exercise is (department, to post, year)**, and
 * `active_flag` makes at most one of them approved-and-active at a time (item 9).
 * The flag is `1` or `NULL`, never `0` — see {@see activate()}.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $pre_dpc_number
 * @property int $department_id
 * @property int|null $dpc_year
 * @property int|null $promotion_to_post_id
 * @property int|null $pay_scale_id
 * @property ServiceClass|null $class_level
 * @property numeric-string $direct_recruitment_pct
 * @property numeric-string $promotion_recruitment_pct
 * @property int $version
 * @property int|null $parent_version_id
 * @property PreDpcStatus $status
 * @property int|null $active_flag
 * @property string|null $government_order_number
 * @property Carbon|null $effective_from
 * @property string|null $rules_document_disk
 * @property string|null $rules_document_path
 * @property string|null $rules_document_name
 * @property string|null $rules_document_sha256
 * @property Carbon|null $prepared_declaration_at
 * @property int|null $prepared_declaration_by
 * @property int|null $prepared_by
 * @property Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $signature_id
 * @property Carbon|null $returned_at
 * @property string|null $return_remarks
 * @property Carbon|null $cancelled_at
 */
class PreDpcProposal extends Model implements Auditable
{
    use AuditableTrait;
    use Blameable;
    use HasUuids;
    use SoftDeletes;

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
     * What the screen may set.
     *
     * The Promotion From Posts are **not** here: an order names several, and they
     * are rows in `pre_dpc_promotion_sources` — see {@see promotionSources()}. Nor
     * are `pay_scale_id` and `class_level` set from a request: they are read off the
     * Promotion To Post's mapping by `PostAttributeResolver`, because a posted pay
     * scale is a pay scale the Commission never approved.
     *
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'dpc_year',
        'promotion_to_post_id',
        'pay_scale_id',
        'class_level',
        'direct_recruitment_pct',
        'promotion_recruitment_pct',
        'pre_dpc_number',
        'version',
        'parent_version_id',
        'status',
        'government_order_number',
        'effective_from',
        'rules_document_disk',
        'rules_document_path',
        'rules_document_name',
        'rules_document_sha256',
        'prepared_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dpc_year' => 'integer',
            'class_level' => ServiceClass::class,
            'status' => PreDpcStatus::class,
            'version' => 'integer',
            'effective_from' => 'date',
            'submitted_at' => 'datetime',
            'declaration_accepted_at' => 'datetime',
            'prepared_declaration_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Every allotment of this proposal to an AO and an AH, oldest first: its
     * allotment history (2026-10-26).
     *
     * @return HasMany<ProposalAllotment, $this>
     */
    public function allotments(): HasMany
    {
        return $this->hasMany(ProposalAllotment::class, 'pre_dpc_proposal_id')->oldest('id');
    }

    /**
     * The AO and AH this proposal is allotted to now, if any.
     *
     * @return HasOne<ProposalAllotment, $this>
     */
    public function currentAllotment(): HasOne
    {
        return $this->hasOne(ProposalAllotment::class, 'pre_dpc_proposal_id')->whereNull('ended_at')->latestOfMany();
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function hasRulesDocument(): bool
    {
        return $this->rules_document_path !== null;
    }

    /**
     * The post officers are promoted **into** — one per order.
     *
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function promotionToPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'promotion_to_post_id');
    }

    /**
     * The Promotion To Post's pay scale, as sanctioned by this order.
     *
     * @return BelongsTo<PayScale, $this>
     */
    public function payScale(): BelongsTo
    {
        return $this->belongsTo(PayScale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /**
     * @return BelongsTo<Signature, $this>
     */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    /**
     * The DPCs raised from this Pre-DPC (change item 11).
     *
     * @return HasMany<DpcProposal, $this>
     */
    public function dpcProposals(): HasMany
    {
        return $this->hasMany(DpcProposal::class);
    }

    /**
     * Every post officers may be promoted **from** under this order.
     *
     * Plural since 2026-08-25, restoring what the single-pair reading of change
     * item 9 had removed: Schedule-IV routinely fills one post from several
     * feeders, each with its own share and its own qualifying service.
     *
     * @return HasMany<PreDpcPromotionSource, $this>
     */
    public function promotionSources(): HasMany
    {
        return $this->hasMany(PreDpcPromotionSource::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Whether this is the live approved Pre-DPC for its exercise (item 9).
     */
    public function isActive(): bool
    {
        return $this->active_flag !== null;
    }

    /**
     * Mark this the live Pre-DPC for its exercise.
     *
     * Does not deactivate the incumbent — `PreDpcProposalService::approve()` does
     * that, under a lock, in the same transaction. Saving this against an existing
     * active row for the same exercise is refused by
     * `pre_dpc_active_exercise_unique`, which is the point.
     */
    public function activate(): void
    {
        $this->active_flag = 1;
    }

    public function deactivate(): void
    {
        $this->active_flag = null;
    }

    /**
     * The one Pre-DPC a DPC may be raised from (items 9 and 11): approved, and
     * active for its exercise.
     *
     * Expressed once, here, because four callers ask it — the DPC creation
     * screen, the DPC creation request, the approval that supersedes a
     * predecessor, and the report. A second copy of "approved and active" is how
     * one of them comes to disagree.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActiveApproved(Builder $query): Builder
    {
        return $query->where('status', PreDpcStatus::Approved->value)->whereNotNull('active_flag');
    }

    /**
     * The columns that identify the exercise this proposal belongs to.
     *
     * **The Promotion From Post left this key on 2026-08-25.** An order sanctions
     * promotion *into* one post for one year and lists every post it may be filled
     * from, so the feeder cannot identify the exercise — there may be three of them.
     *
     * @return array<string, mixed>
     */
    public function exerciseKey(): array
    {
        return [
            'department_id' => $this->department_id,
            'promotion_to_post_id' => $this->promotion_to_post_id,
            'dpc_year' => $this->dpc_year,
        ];
    }

    /**
     * How the exercise reads on a screen.
     *
     * "Assistant Director, Senior Lecturer → Deputy Director (2026)" — every feeder
     * named, because which posts a promotion draws on is the first thing a reader
     * needs and the whole reason more than one is allowed.
     */
    public function exerciseLabel(): string
    {
        $from = $this->promotionSources
            ->map(fn (PreDpcPromotionSource $source): string => $source->promotionFromPost->name)
            ->implode(', ');

        return sprintf(
            '%s → %s (%s)',
            $from === '' ? '—' : $from,
            $this->promotionToPost === null ? '—' : $this->promotionToPost->name,
            $this->dpc_year ?? '—',
        );
    }
}
