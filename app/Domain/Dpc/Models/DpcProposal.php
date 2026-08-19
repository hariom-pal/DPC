<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\Signature\Models\Signature;
use App\Support\Concerns\Blameable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * DPC Proposal aggregate root (PPT S12–S25). Unlike Pre-DPC, this is not a
 * versioned department singleton — a department has many proposals over
 * time, one per post pair — so there is no version/is_current/parent
 * lineage. Once APPROVED, correction happens via a new Review DPC proposal
 * (M8) referencing this one, not by amending this record in place.
 *
 * @property int $id
 * @property int $department_id
 * @property int $dpc_type_id
 * @property int|null $original_dpc_proposal_id
 * @property int|null $review_reason_id
 * @property int $post_to_cadre_id
 * @property int $post_from_cadre_id
 * @property bool $legal_stay
 * @property Carbon $proposal_date
 * @property int|null $sanctioned_post_count
 * @property DpcProposalStatus $status
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
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'dpc_type_id',
        'original_dpc_proposal_id',
        'review_reason_id',
        'post_to_cadre_id',
        'post_from_cadre_id',
        'legal_stay',
        'proposal_date',
        'sanctioned_post_count',
        'status',
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
            'status' => DpcProposalStatus::class,
            'forwarded_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<Cadre, $this>
     */
    public function postToCadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class, 'post_to_cadre_id');
    }

    /**
     * @return BelongsTo<Cadre, $this>
     */
    public function postFromCadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class, 'post_from_cadre_id');
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
     * @return HasMany<DpcCommitteeMember, $this>
     */
    public function committeeMembers(): HasMany
    {
        return $this->hasMany(DpcCommitteeMember::class)->orderBy('sort_order');
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
}
