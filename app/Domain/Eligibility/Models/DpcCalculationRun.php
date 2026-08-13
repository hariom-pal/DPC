<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\CalculationStatus;
use App\Domain\Eligibility\RuleSet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * One execution of the Eligibility Engine against one proposal (PPT S33) —
 * M5 Phase 3.
 *
 * Self-contained by design (architect Decision 1): the run carries the entire
 * rule set it executed under, a fingerprint of the officer data it consumed,
 * who ran it, when, and which build did it. Re-running a 2026 calculation in
 * 2031 reads none of today's configuration.
 *
 * **Immutable once complete.** No role, including an administrator, may edit a
 * completed run — enforced here at the model layer rather than only in a
 * policy, so the guarantee holds for a console command, a queued job, or a
 * future developer who never sees the policy. Recalculation creates a new run.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property CalculationStatus $status
 * @property string $rule_version
 * @property array<string, mixed> $configuration_snapshot
 * @property string $proposal_version
 * @property string $application_version
 * @property string $git_commit_hash
 * @property Carbon|null $build_timestamp
 * @property int|null $executed_by
 * @property Carbon $executed_at
 * @property array<string, mixed> $zone
 * @property int $officers_considered
 * @property int $eligible_count
 * @property int $main_list_count
 * @property int $waiting_list_count
 * @property bool $has_pending_decisions
 * @property array<int, string>|null $pending_decisions
 */
class DpcCalculationRun extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'status',
        'rule_version',
        'configuration_snapshot',
        'proposal_version',
        'application_version',
        'git_commit_hash',
        'build_timestamp',
        'executed_by',
        'executed_at',
        'zone',
        'officers_considered',
        'eligible_count',
        'main_list_count',
        'waiting_list_count',
        'has_pending_decisions',
        'pending_decisions',
    ];

    /**
     * The public identifier is the UUID; the auto-increment id stays internal
     * (architect directive). A sequential id in a legal document discloses how
     * many calculations exist and invites tampering by guessing neighbours.
     *
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
            'status' => CalculationStatus::class,
            'configuration_snapshot' => 'array',
            'zone' => 'array',
            'pending_decisions' => 'array',
            'build_timestamp' => 'datetime',
            'executed_at' => 'datetime',
            'officers_considered' => 'integer',
            'eligible_count' => 'integer',
            'main_list_count' => 'integer',
            'waiting_list_count' => 'integer',
            'has_pending_decisions' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            // The only permitted update is Draft -> a terminal state, which is
            // how a run is completed. Everything else is history.
            $original = $run->getOriginal('status');
            $wasDraft = $original === CalculationStatus::Draft->value || $original === CalculationStatus::Draft;

            if (! $wasDraft) {
                throw new RuntimeException(
                    'A completed calculation run is immutable and cannot be modified. Run the calculation again to '
                    .'produce a new run; history is never overwritten.'
                );
            }
        });

        static::deleting(function (self $run): void {
            throw new RuntimeException('Calculation runs are permanent evidence and cannot be deleted.');
        });
    }

    /**
     * Rebuild the exact rules this run executed under, from the run itself.
     */
    public function ruleSet(): RuleSet
    {
        return RuleSet::fromSnapshot($this->configuration_snapshot);
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
    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * @return HasMany<DpcCalculationResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(DpcCalculationResult::class)->orderBy('sn');
    }

    /**
     * @return HasMany<DpcCalculationResult, $this>
     */
    public function mainList(): HasMany
    {
        return $this->hasMany(DpcCalculationResult::class)->where('list_type', 'main')->orderBy('list_position');
    }

    /**
     * @return HasMany<DpcCalculationResult, $this>
     */
    public function waitingList(): HasMany
    {
        return $this->hasMany(DpcCalculationResult::class)->where('list_type', 'waiting')->orderBy('list_position');
    }
}
