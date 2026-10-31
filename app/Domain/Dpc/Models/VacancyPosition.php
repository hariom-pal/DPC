<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\PreDpc\Models\PreDpcProposal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The Vacancy Master position of one post of one department for one Vacancy Year,
 * at one revision. See the migration for the design.
 *
 * @property int $id
 * @property string $uuid
 * @property int $department_id
 * @property string $post_name
 * @property int|null $department_post_id
 * @property int|null $pre_dpc_proposal_id
 * @property int $vacancy_year
 * @property int $revision
 * @property VacancyPositionStatus $status
 * @property int|null $supersedes_id
 * @property string|null $revision_reason
 * @property int|null $working_strength_id
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property int|null $sanctioned_strength
 * @property int|null $recruitment_rule_id
 * @property string|null $promotion_percentage
 * @property string|null $rounding_rule
 * @property int|null $opening_vacancy
 * @property int|null $promotion_vacancy
 * @property int|null $direct_vacancy
 * @property array<string, mixed>|null $breakdown
 * @property int|null $sanctioned_strength_id
 * @property int|null $created_by
 * @property int|null $finalized_by
 * @property Carbon|null $finalized_at
 */
class VacancyPosition extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    protected $fillable = [
        'department_id',
        'post_name',
        'department_post_id',
        'pre_dpc_proposal_id',
        'vacancy_year',
        'revision',
        'status',
        'supersedes_id',
        'revision_reason',
        'working_strength_id',
        'sanctioned_strength_id',
        'period_from',
        'period_to',
        'sanctioned_strength',
        'recruitment_rule_id',
        'promotion_percentage',
        'rounding_rule',
        'opening_vacancy',
        'promotion_vacancy',
        'direct_vacancy',
        'breakdown',
        'created_by',
        'finalized_by',
        'finalized_at',
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
            'status' => VacancyPositionStatus::class,
            'vacancy_year' => 'integer',
            'revision' => 'integer',
            'period_from' => 'date',
            'period_to' => 'date',
            'sanctioned_strength' => 'integer',
            'promotion_percentage' => 'decimal:2',
            'opening_vacancy' => 'integer',
            'promotion_vacancy' => 'integer',
            'direct_vacancy' => 'integer',
            'breakdown' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === VacancyPositionStatus::Draft;
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The mapping in force on 1 January of the Vacancy Year.
     *
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function departmentPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class);
    }

    /**
     * The approved Pre-DPC this record counts the vacancies of (2026-10-30): its
     * post, its year and its promotion share.
     *
     * @return BelongsTo<PreDpcProposal, $this>
     */
    public function preDpcProposal(): BelongsTo
    {
        return $this->belongsTo(PreDpcProposal::class);
    }

    /**
     * @return BelongsTo<RecruitmentRule, $this>
     */
    public function recruitmentRule(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRule::class);
    }

    /**
     * The sanctioned strength in force for this revision's Vacancy Year — entered year
     * by year, not read from the Department Post Mapping.
     *
     * @return BelongsTo<VacancyPositionSanctionedStrength, $this>
     */
    public function sanctionedStrength(): BelongsTo
    {
        return $this->belongsTo(VacancyPositionSanctionedStrength::class, 'sanctioned_strength_id');
    }

    /**
     * Every sanctioned strength recorded for this revision, oldest first.
     *
     * @return HasMany<VacancyPositionSanctionedStrength, $this>
     */
    public function sanctionedStrengthHistory(): HasMany
    {
        return $this->hasMany(VacancyPositionSanctionedStrength::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<VacancyPositionWorkingStrength, $this>
     */
    public function workingStrength(): BelongsTo
    {
        return $this->belongsTo(VacancyPositionWorkingStrength::class, 'working_strength_id');
    }

    /**
     * Every working strength recorded for this revision, oldest first.
     *
     * @return HasMany<VacancyPositionWorkingStrength, $this>
     */
    public function workingStrengthHistory(): HasMany
    {
        return $this->hasMany(VacancyPositionWorkingStrength::class)->orderBy('id');
    }

    /**
     * @return HasMany<VacancyPositionEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(VacancyPositionEntry::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<VacancyPosition, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
