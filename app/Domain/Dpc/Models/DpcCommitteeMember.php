<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Enums\MemberCategory;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Enums\SignatureStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One person's membership of **one DPC's** committee (PPT S15).
 *
 * **This row is the whole record of a committee membership** (change register of
 * 2026-08-26, items 7 and 8). Until 2026-08-26 it was a pointer into a
 * `commission_members` master that held the member's standing; that master is
 * withdrawn, and the register is explicit about why:
 *
 * > *Committee Members are NOT permanent system roles. Committee Membership is
 * > valid only for that specific DPC. This should be stored only in the DPC
 * > Committee assignment.*
 *
 * So the composition attributes live here, on the assignment. A person is not
 * permanently "the ST member"; they are the member who represented ST **on this
 * committee, on this DPC**. The same person may sit on the next DPC representing
 * nothing, and may simultaneously be an Authorized Officer, an Administrative Head
 * and a Promotion Candidate elsewhere in the portal — none of which this row
 * grants, implies or reads.
 *
 * **Sitting here grants no role.** There is no `assignRole` anywhere on this
 * model's write path, and `CommitteeMembershipGrantsNoRoleTest` pins it.
 *
 * ### The name is a snapshot, and stays one
 *
 * `name`, `designation`, `mobile` and `email` are copied onto the row when the
 * committee is recorded rather than joined from the user. A committee records who
 * sat on it; editing an account two years later must not rewrite a proceeding that
 * has already been signed. `user_id` is who signs in; these columns are what the
 * record says.
 *
 * ### Replacement is a chain, not an edit
 *
 * A member may be replaced at any stage (item 9e). The outgoing row keeps
 * everything it had and gains `replaced_at`, `replaced_by` and
 * `replacement_reason`; the incoming row points back through `replaces_id`.
 * `active_flag` is `1` on the serving row and NULL on every replaced one, which is
 * what lets `unique(dpc_proposal_id, user_id, active_flag)` mean *one serving row
 * per person per DPC, any number of replaced ones*.
 *
 * A proceeding that already cites a member stays truthful, which is the only
 * reason this is a chain.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $designation
 * @property string|null $mobile
 * @property string|null $email
 * @property CommitteeCapacity $capacity
 * @property bool $is_sc
 * @property bool $is_st
 * @property bool $is_obc
 * @property bool $is_female
 * @property bool $is_bhar_sadhak_sachiv
 * @property bool $is_vibhag_sachiv
 * @property int|null $is_dpc_chairman
 * @property int $sort_order
 * @property int|null $active_flag
 * @property Carbon|null $replaced_at
 * @property string|null $replacement_reason
 * @property int|null $replaced_by
 * @property int|null $replaces_id
 * @property SignatureStatus|null $signature_status
 * @property Carbon|null $signed_at
 * @property string|null $signature_reference
 */
class DpcCommitteeMember extends Model implements Auditable
{
    use AuditableTrait;

    /**
     * The six composition attributes, as columns.
     *
     * Written out rather than derived from {@see MemberCategory}, deliberately.
     * These names are mass-assignable and are read straight off a request; deriving
     * them from an enum would mean a new enum case silently opened a new
     * mass-assignable column. The enum and this list are cross-checked by
     * `CommitteeAttributeTest`, so they cannot drift apart unnoticed.
     *
     * @var list<string>
     */
    public const ATTRIBUTE_COLUMNS = [
        'is_sc',
        'is_st',
        'is_obc',
        'is_female',
        'is_bhar_sadhak_sachiv',
        'is_vibhag_sachiv',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'user_id',
        'name',
        'designation',
        'mobile',
        'email',
        'capacity',
        'is_sc',
        'is_st',
        'is_obc',
        'is_female',
        'is_bhar_sadhak_sachiv',
        'is_vibhag_sachiv',
        'is_dpc_chairman',
        'sort_order',
        'active_flag',
        'replaces_id',
        'signature_status',
        'signed_at',
        'signature_reference',
    ];

    /**
     * The replacement columns are **not** fillable.
     *
     * Retiring a member is an act with an actor and a reason, performed by
     * `DpcCommitteeService::replace()` under a transaction. A payload that could
     * set `replaced_at` could retire a member without recording who did it, which
     * is the one thing the replacement record exists to prevent.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => CommitteeCapacity::class,
            'is_sc' => 'boolean',
            'is_st' => 'boolean',
            'is_obc' => 'boolean',
            'is_female' => 'boolean',
            'is_bhar_sadhak_sachiv' => 'boolean',
            'is_vibhag_sachiv' => 'boolean',
            'sort_order' => 'integer',
            'replaced_at' => 'datetime',
            'signature_status' => SignatureStatus::class,
            'signed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who replaced this member.
     *
     * @return BelongsTo<User, $this>
     */
    public function replacer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }

    /**
     * The member this row replaced, if it replaced one.
     *
     * @return BelongsTo<self, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    /**
     * Members currently on the committee.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeServing(Builder $query): Builder
    {
        return $query->whereNotNull('active_flag');
    }

    /**
     * Members who were replaced.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeReplaced(Builder $query): Builder
    {
        return $query->whereNull('active_flag');
    }

    public function isServing(): bool
    {
        return $this->active_flag !== null;
    }

    public function wasReplaced(): bool
    {
        return $this->replaced_at !== null;
    }

    /**
     * Whether this is the CGPSC Chairman's seat, which the AO may not touch.
     */
    public function isCgpscChairman(): bool
    {
        return $this->capacity === CommitteeCapacity::CgpscChairman;
    }

    /**
     * Whether this member conducts this DPC (item 9, as clarified).
     */
    public function isDpcChairman(): bool
    {
        return $this->is_dpc_chairman !== null;
    }

    /**
     * A row that names no login.
     *
     * Since 2026-08-26 the committee screen creates an account for a member it
     * cannot find, so new rows always name one. Committees recorded before that
     * may not, and every caller has to keep tolerating it.
     */
    public function isExternal(): bool
    {
        return $this->user_id === null;
    }

    /**
     * What this member represents on this committee.
     *
     * @return list<MemberCategory>
     */
    public function attributes(): array
    {
        return array_values(array_filter(
            MemberCategory::cases(),
            fn (MemberCategory $category): bool => $category->representedBy($this),
        ));
    }

    public function represents(MemberCategory $category): bool
    {
        return $category->representedBy($this);
    }

    /**
     * The one reserved social category this member holds, or null for UR
     * (General) — business clarification of 2026-09-05.
     *
     * Null rather than a category case, because UR is the absence of a claim
     * rather than a claim; see {@see MemberCategory::claimedSocialCategory()}.
     * Callers that want something to print should use
     * {@see socialCategoryLabel()}, which resolves the null.
     */
    public function socialCategory(): ?MemberCategory
    {
        foreach (MemberCategory::socialCategories() as $category) {
            if ($category->representedBy($this)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * The social category as it reads on a screen — "SC", or "UR (General)".
     *
     * Never null, unlike {@see attributeLabel()}: every member has a social
     * category, and for most of them it is the one nobody ticked.
     */
    public function socialCategoryLabel(): string
    {
        return $this->socialCategory()?->label() ?? MemberCategory::unreservedLabel();
    }

    /**
     * The attributes this member holds besides their social category — "Female /
     * Vibhag Sachiv", or null when they hold none.
     */
    public function additionalAttributeLabel(): ?string
    {
        $labels = array_map(
            static fn (MemberCategory $category): string => $category->label(),
            array_values(array_filter(
                MemberCategory::additionalAttributes(),
                fn (MemberCategory $category): bool => $category->representedBy($this),
            )),
        );

        return $labels === [] ? null : implode(' / ', $labels);
    }

    /**
     * How this member's attributes read on a screen — "ST / Female / Vibhag
     * Sachiv", or null when they represent none.
     *
     * **Still returns null for a member who represents nothing**, rather than
     * "UR (General)". This is the badge shown *beside* a name where there is
     * something worth saying, and printing "UR (General)" against every
     * unremarkable member would be noise. Screens that must always state the
     * social category call {@see socialCategoryLabel()} instead.
     */
    public function attributeLabel(): ?string
    {
        $labels = array_map(
            static fn (MemberCategory $category): string => $category->label(),
            $this->attributes(),
        );

        return $labels === [] ? null : implode(' / ', $labels);
    }
}
