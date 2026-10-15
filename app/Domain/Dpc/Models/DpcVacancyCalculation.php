<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecruitmentRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * One recorded vacancy calculation (change register of 2026-08-27, item 10).
 *
 * > *Store complete vacancy calculation for audit.*
 *
 * **Append-only**, like every other calculation in this portal. Recalculating
 * writes a new row and marks the previous one superseded; nothing is edited in
 * place, and the model refuses to be. The Commission acts on a vacancy figure, and
 * if a promotion is contested the question is what the engine produced *at that
 * moment, from the sources in force then* — a row that could be edited could not
 * answer it.
 *
 * **Why this is stored at all**, when the old total was derived on read: the
 * calculation now depends on five things that live elsewhere and change — the
 * sanctioned strength on the Department Post Mapping, the promotion share on the
 * Recruitment Rule, and each officer's retirement date and deputation period.
 * A figure derived from five moving sources cannot be recomputed later and
 * guaranteed to give the answer that was acted on.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property int|null $sanctioned_from_post_id
 * @property int $sanctioned_strength
 * @property int|null $recruitment_rule_id
 * @property numeric-string $promotion_percentage
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property int $working_strength
 * @property int $base_vacancy
 * @property int $retirement_vacancy
 * @property int $deputation_vacancy
 * @property int $total_vacancy
 * @property numeric-string $promotion_vacancy_exact
 * @property int $promotion_vacancy
 * @property int $direct_recruitment_vacancy
 * @property string $rounding_rule
 * @property array<int, array<string, mixed>> $contributors
 * @property array<string, mixed> $inputs
 * @property int|null $calculated_by
 * @property Carbon $calculated_at
 * @property int|null $superseded_by_id
 */
class DpcVacancyCalculation extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

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
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'sanctioned_from_post_id',
        'sanctioned_strength',
        'recruitment_rule_id',
        'promotion_percentage',
        'period_from',
        'period_to',
        'working_strength',
        'base_vacancy',
        'retirement_vacancy',
        'deputation_vacancy',
        'total_vacancy',
        'promotion_vacancy_exact',
        'promotion_vacancy',
        'direct_recruitment_vacancy',
        'rounding_rule',
        'contributors',
        'inputs',
        'calculated_by',
        'calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'working_strength' => 'integer',
            'sanctioned_strength' => 'integer',
            'base_vacancy' => 'integer',
            'retirement_vacancy' => 'integer',
            'deputation_vacancy' => 'integer',
            'total_vacancy' => 'integer',
            'promotion_vacancy' => 'integer',
            'direct_recruitment_vacancy' => 'integer',
            'promotion_percentage' => 'decimal:2',
            'promotion_vacancy_exact' => 'decimal:2',
            'contributors' => 'array',
            'inputs' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * The one column an update may touch.
     *
     * Being superseded is a lifecycle fact about the row; everything else is what
     * the calculation *was*, and is fixed the moment it is written.
     */
    private const PERMITTED_UPDATES = ['superseded_by_id', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $calculation): void {
            $forbidden = array_diff(array_keys($calculation->getDirty()), self::PERMITTED_UPDATES);

            if ($forbidden !== []) {
                throw new RuntimeException(sprintf(
                    'A vacancy calculation is a record of what the engine produced and is not edited: '
                    .'attempted to change %s. Recalculate instead — the previous calculation is kept.',
                    implode(', ', $forbidden),
                ));
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'A vacancy calculation is a permanent record and cannot be deleted. Recalculating supersedes it.'
            );
        });
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }

    /**
     * The mapping the sanctioned strength was read from.
     *
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function sanctionedFromPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'sanctioned_from_post_id');
    }

    /**
     * The rule the promotion percentage was read from.
     *
     * @return BelongsTo<RecruitmentRule, $this>
     */
    public function recruitmentRule(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRule::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    /**
     * The live calculation — the one with no successor.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_by_id');
    }

    /**
     * Which officers contributed a vacancy on a given ground.
     *
     * @return list<array<string, mixed>>
     */
    public function contributorsOn(string $ground): array
    {
        return array_values(array_filter(
            $this->contributors,
            static fn (array $entry): bool => ($entry['ground'] ?? null) === $ground,
        ));
    }
}
