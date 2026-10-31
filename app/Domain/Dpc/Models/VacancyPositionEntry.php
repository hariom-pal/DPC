<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\VacancyEntryEligibility;
use App\Domain\Dpc\Enums\VacancyEntryKind;
use App\Domain\Dpc\Enums\VacancyReason;
use App\Domain\Dpc\Enums\VacancyReconciliation;
use App\Domain\Master\Models\DepartmentPost;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One reason-wise vacancy entry of a Vacancy Master position.
 *
 * @property int $id
 * @property string $uuid
 * @property int $vacancy_position_id
 * @property VacancyEntryKind $kind
 * @property VacancyReason $reason
 * @property string|null $occupant_quota
 * @property int $vacancy_count
 * @property Carbon $effective_date
 * @property string|null $order_number
 * @property Carbon|null $order_date
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $original_name
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property string|null $remarks
 * @property VacancyReconciliation|null $reconciliation
 * @property int|null $higher_post_id
 * @property string|null $higher_dpc_reference
 * @property int|null $higher_dpc_proposal_id
 * @property string|null $promotion_order_number
 * @property Carbon|null $promotion_order_date
 * @property Carbon|null $deputation_to
 * @property int|null $deputation_expected_months
 * @property bool|null $against_sanctioned_post
 * @property string|null $cadre_details
 * @property string|null $officer_name
 * @property Carbon|null $deputation_from
 * @property bool|null $promotion_made
 * @property VacancyEntryEligibility $eligibility
 * @property string|null $eligibility_note
 * @property string|null $verification_basis
 * @property string|null $verification_remarks
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string $status
 * @property string|null $withdrawal_reason
 * @property int|null $withdrawn_by
 * @property Carbon|null $withdrawn_at
 * @property int|null $recorded_by
 */
class VacancyPositionEntry extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'vacancy_position_id',
        'kind',
        'reason',
        'occupant_quota',
        'vacancy_count',
        'officer_name',
        'effective_date',
        'order_number',
        'order_date',
        'disk',
        'path',
        'original_name',
        'size_bytes',
        'sha256',
        'remarks',
        'reconciliation',
        'higher_post_id',
        'higher_dpc_reference',
        'higher_dpc_proposal_id',
        'promotion_order_number',
        'promotion_order_date',
        'higher_post_undertaking_at',
        'deputation_to',
        'deputation_expected_months',
        'against_sanctioned_post',
        'cadre_details',
        'deputation_from',
        'promotion_made',
        'eligibility',
        'eligibility_note',
        'verification_basis',
        'verification_remarks',
        'verified_by',
        'verified_at',
        'status',
        'withdrawal_reason',
        'withdrawn_by',
        'withdrawn_at',
        'recorded_by',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => VacancyEntryKind::class,
            'reason' => VacancyReason::class,
            'reconciliation' => VacancyReconciliation::class,
            'eligibility' => VacancyEntryEligibility::class,
            'vacancy_count' => 'integer',
            'effective_date' => 'date',
            'order_date' => 'date',
            'promotion_order_date' => 'date',
            'higher_post_undertaking_at' => 'datetime',
            'deputation_to' => 'date',
            'deputation_expected_months' => 'integer',
            'against_sanctioned_post' => 'boolean',
            'deputation_from' => 'date',
            'promotion_made' => 'boolean',
            'size_bytes' => 'integer',
            'verified_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether this entry adds to the vacancy position: an active, eligible,
     * anticipated vacancy whose officer was counted in the 1 January working
     * strength. Nothing else adds.
     */
    public function isCounted(): bool
    {
        if (! $this->isActive() || $this->eligibility !== VacancyEntryEligibility::Eligible) {
            return false;
        }

        // Deputation rows are judged against the Vacancy Year when saved: eligible is
        // counted, not eligible is recorded only.
        return match ($this->kind) {
            VacancyEntryKind::Deputation, VacancyEntryKind::DeputationIn => true,
            // An anticipated row since 2026-10-30 asks no reconciliation: its officer
            // is in the working strength. An older row still answers by its own.
            VacancyEntryKind::Anticipated => $this->reconciliation === null || $this->reconciliation === VacancyReconciliation::CountedInWorking,
            VacancyEntryKind::Existing => false,
        };
    }

    /**
     * @return BelongsTo<VacancyPosition, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(VacancyPosition::class, 'vacancy_position_id');
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function higherPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'higher_post_id');
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function higherDpcProposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'higher_dpc_proposal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
