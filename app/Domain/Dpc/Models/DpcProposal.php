<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Signature\Models\Signature;
use App\Enums\ServiceClass;
use App\Support\Concerns\Blameable;
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
 * DPC Proposal aggregate root (PPT S12–S25).
 *
 * **Raised from an approved Pre-DPC, and only from one** (change item 11,
 * 2026-08-24). There is no standalone "New DPC": a DPC exists because an active
 * approved Pre-DPC sanctioned that promotion exercise, and it inherits its
 * Department, DPC Year, Promotion From Post, Promotion To Post, Recruitment Rule,
 * Class, Pay Scale and Promotion Duration from it. The inherited values are stored
 * rather than read live, so a later amendment to the Pre-DPC cannot restate the
 * terms a concluded promotion ran under.
 *
 * **A return for correction bumps the revision, never the number** (item 18). The
 * DPC Number is issued once, at creation, and is the same string through every
 * round trip; {@see revisions()} is the record of those trips. A genuinely new
 * exercise — additional vacancies, a Review DPC, a court-ordered DPC — is a new
 * proposal and therefore a new number at revision 1 (item 19), which is why there
 * is still no version/parent lineage on this model: the lineage between exercises
 * is `original_dpc_proposal_id`, and the lineage *within* one is the revision.
 *
 * @property int $id
 * @property int $department_id
 * @property int|null $pre_dpc_proposal_id
 * @property int|null $dpc_year
 * @property Carbon|null $seniority_list_as_on
 * @property string|null $vacancy_source
 * @property int $dpc_type_id
 * @property int|null $original_dpc_proposal_id
 * @property int|null $review_reason_id
 * @property int $promotion_to_post_id
 * @property int|null $promotion_from_post_id
 * @property int|null $pay_scale_id
 * @property ServiceClass|null $class_level
 * @property int|null $promotion_duration_years
 * @property int|null $recruitment_rule_id
 * @property bool $legal_stay
 * @property Carbon $proposal_date
 * @property int|null $sanctioned_post_count
 * @property DpcProposalStatus $status
 * @property int $revision_number
 * @property string|null $dpc_code
 * @property Carbon|null $forwarded_at
 * @property int|null $prepared_by
 * @property Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $signature_id
 * @property Carbon|null $returned_at
 * @property string|null $return_remarks
 * @property Carbon|null $cancelled_at
 */
class DpcProposal extends Model implements Auditable
{
    use AuditableTrait;
    use Blameable;

    /**
     * **TD-19, closed 2026-08-19 under the final-phase Freeze Exception.**
     *
     * Directive 2 (M7) requires an approved result to reference its proposal
     * permanently by UUID, and the standing rule is that the UUID is the only
     * public identifier. The column was added and backfilled by migration, but a
     * newly created proposal had its UUID minted by **direct table update** in two
     * separate services — `ProceedingService::ensureProposalUuid()` and
     * `ReviewDpcService::create()` — because adding this trait would have modified
     * frozen M4. Each new caller was another place the workaround had to be
     * remembered.
     *
     * `HasUuids` overrides `newUniqueId()` and `uniqueIds()` only, so the id column
     * and every existing behaviour are untouched: `id` remains the primary key and
     * the route key is unchanged. Rows created before this line keep the UUIDs the
     * migration gave them.
     */
    use HasUuids;

    use SoftDeletes;

    /** Vacancy figures from the AO's Working Officers chart (proposals raised before 2026-10-17). */
    public const VACANCY_SOURCE_CHART = 'chart';

    /** Vacancy figures from the Administrator's finalized Vacancy Management record. */
    public const VACANCY_SOURCE_MASTER = 'vacancy_master';

    /**
     * The vacancy source is stamped once, at creation, so changing the setting never
     * changes a proposal already raised.
     */
    protected static function booted(): void
    {
        static::creating(function (DpcProposal $proposal): void {
            if ($proposal->vacancy_source === null) {
                $source = (string) config('dpc.vacancy_master.source_for_new_proposals', self::VACANCY_SOURCE_MASTER);
                $proposal->vacancy_source = $source === self::VACANCY_SOURCE_CHART ? self::VACANCY_SOURCE_CHART : self::VACANCY_SOURCE_MASTER;
            }
        });
    }

    /**
     * Whether this proposal's vacancy figures are the Administrator's finalized
     * Vacancy Management record rather than an AO-entered chart.
     */
    public function usesVacancyMaster(): bool
    {
        return $this->vacancy_source === self::VACANCY_SOURCE_MASTER;
    }

    /**
     * The columns `HasUuids` fills on create.
     *
     * Declared explicitly: the trait's default is the model's *key* name, which
     * here is the auto-incrementing `id`. Without this it would try to write a
     * UUID into the primary key.
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
     * **Why this was outstanding, and why it is closed now.** The UUID column
     * arrived in M7 to keep sequential ids out of *signed documents*, and the
     * migration that added it says in terms that the route key was left alone
     * "so no relation and no URL changes" — because changing it would have
     * touched frozen M4. TD-19 then recorded the trait as the closure. Neither
     * step delivered the half of AD-8 that says an id never appears in a URL:
     * every DPC screen was still `/dpc-proposals/4`.
     *
     * **It was not only a disclosure question.** With `id` as the route key,
     * MySQL resolves the binding by coercing the segment to an integer, so
     * `/dpc-proposals/01a0282e-b21f-.../committee` silently served **proposal
     * 1** — a different record, with a 200, rather than a 404. Every other
     * aggregate in the application already binds on `uuid` and was never
     * exposed to that.
     *
     * Behaviour-preserving for the database: `id` remains the primary key and
     * every foreign key still references it. Only the public URL changes.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'pre_dpc_proposal_id',
        'dpc_year',
        'dpc_type_id',
        'original_dpc_proposal_id',
        'review_reason_id',
        'promotion_to_post_id',
        'promotion_from_post_id',
        'pay_scale_id',
        'class_level',
        'promotion_duration_years',
        'recruitment_rule_id',
        'legal_stay',
        'proposal_date',
        'sanctioned_post_count',
        'status',
        'revision_number',
        'dpc_code',
        'forwarded_at',
        'prepared_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'signature_id',
        'returned_at',
        'return_remarks',
        'cancelled_at',
        'seniority_list_as_on',
        'vacancy_source',
        'vacancy_position_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legal_stay' => 'boolean',
            'proposal_date' => 'date',
            'sanctioned_post_count' => 'integer',
            'dpc_year' => 'integer',
            'class_level' => ServiceClass::class,
            'promotion_duration_years' => 'integer',
            'revision_number' => 'integer',
            'status' => DpcProposalStatus::class,
            'forwarded_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'seniority_list_as_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<DpcType, $this>
     */
    public function dpcType(): BelongsTo
    {
        return $this->belongsTo(DpcType::class);
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function promotionToPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'promotion_to_post_id');
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function promotionFromPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'promotion_from_post_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function originalProposal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_dpc_proposal_id');
    }

    /**
     * @return BelongsTo<ReviewReason, $this>
     */
    public function reviewReason(): BelongsTo
    {
        return $this->belongsTo(ReviewReason::class);
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
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The Pre-DPC that sanctioned this exercise (change item 11).
     *
     * @return BelongsTo<PreDpcProposal, $this>
     */
    public function preDpcProposal(): BelongsTo
    {
        return $this->belongsTo(PreDpcProposal::class, 'pre_dpc_proposal_id');
    }

    /**
     * @return BelongsTo<PayScale, $this>
     */
    public function payScale(): BelongsTo
    {
        return $this->belongsTo(PayScale::class);
    }

    /**
     * The versioned rule this DPC was raised under.
     *
     * @return BelongsTo<RecruitmentRule, $this>
     */
    public function recruitmentRule(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRule::class);
    }

    /**
     * Every round trip this proposal has been through (change item 18), oldest
     * first — which is the order a reader wants: "submitted, returned because X,
     * resubmitted, returned because Y".
     *
     * @return HasMany<DpcProposalRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(DpcProposalRevision::class)->orderBy('revision_number');
    }

    /**
     * The revision now in progress.
     *
     * @return HasOne<DpcProposalRevision, $this>
     */
    public function currentRevision(): HasOne
    {
        return $this->hasOne(DpcProposalRevision::class)->latestOfMany('revision_number');
    }

    /**
     * How the proposal is cited: "DPC-2026-HED-0001 (Revision 2)".
     *
     * One method rather than the same concatenation on six screens, because the
     * number and the revision are only meaningful together — the number alone no
     * longer identifies which submission is being discussed.
     */
    public function citation(): string
    {
        return sprintf('%s (Revision %d)', $this->dpc_code ?? '—', $this->revision_number);
    }

    /**
     * The committee **as it stands** — the members currently serving.
     *
     * Scoped to serving rows since 2026-08-26, when members became replaceable
     * (item 9e). Every existing caller wants this reading and would be wrong
     * without it: the completeness check counts these against the size bounds, the
     * proceeding and order documents print them, and the review chain copies them.
     * A replaced member appearing in any of those would overstate the committee and
     * put somebody on a document who had stood down.
     *
     * {@see committeeHistory()} is the unscoped relation, for the one screen that
     * wants the substitutions.
     *
     * @return HasMany<DpcCommitteeMember, $this>
     */
    public function committeeMembers(): HasMany
    {
        return $this->hasMany(DpcCommitteeMember::class)->serving()->orderBy('sort_order');
    }

    /**
     * Every committee row ever recorded against this DPC, replaced ones included.
     *
     * Ordered so a substitution reads as a sequence: the seat, then the order in
     * which its occupants held it.
     *
     * @return HasMany<DpcCommitteeMember, $this>
     */
    public function committeeHistory(): HasMany
    {
        return $this->hasMany(DpcCommitteeMember::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<DpcVacancy, $this>
     */
    public function vacancies(): HasMany
    {
        return $this->hasMany(DpcVacancy::class);
    }

    /**
     * @return HasMany<DpcWorkingOfficer, $this>
     */
    public function workingOfficers(): HasMany
    {
        return $this->hasMany(DpcWorkingOfficer::class)->orderBy('sn');
    }

    /**
     * @return HasMany<DpcSeniorityEntry, $this>
     */
    public function seniorityEntries(): HasMany
    {
        return $this->hasMany(DpcSeniorityEntry::class)->orderBy('seniority_number');
    }

    /**
     * @return HasMany<DpcEvaluationRow, $this>
     */
    public function evaluationRows(): HasMany
    {
        return $this->hasMany(DpcEvaluationRow::class)->orderBy('sn');
    }

    /**
     * The finalized Vacancy Master position this proposal was submitted against.
     *
     * @return BelongsTo<VacancyPosition, $this>
     */
    public function vacancyPosition(): BelongsTo
    {
        return $this->belongsTo(VacancyPosition::class);
    }

    /**
     * Rule 1.6: the final seniority list is drawn up as on 1 April of the year
     * preceding the DPC year. Derived from the DPC year alone — never from the
     * current date, the upload date or a meeting date. The department states the
     * date its list was drawn up as on (`seniority_list_as_on`) when it saves the
     * list, and the statement is refused unless it is this date.
     */
    public function seniorityReferenceDate(): Carbon
    {
        $year = $this->dpc_year ?: $this->proposal_date->year;

        return Carbon::create((int) $year - 1, 4, 1)->startOfDay();
    }

    /**
     * Every Rule 1.1 confirmation and withdrawal, oldest first — the history.
     *
     * @return HasMany<DpcProposalVerification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(DpcProposalVerification::class)->orderBy('id');
    }
}
