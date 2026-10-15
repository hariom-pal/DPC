<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Where the committee has got to with one candidate at one sitting (business
 * instruction, 2026-09-07).
 *
 * A row exists only once something has happened: a candidate the committee has not
 * reached yet has none. That is what lets the screen tell *"not called"* from
 * *"called, nothing recorded"* — a distinction a committee minute has to be able to
 * make.
 *
 * **No marks.** Business said the marking scheme is undecided, so this records
 * *that* a candidate was called and *that* they were concluded, with who and when.
 * A score column invented now would be inventing the scheme.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proceeding_id
 * @property int $dpc_evaluation_row_id
 * @property Carbon|null $called_at
 * @property int|null $called_by
 * @property Carbon|null $concluded_at
 * @property int|null $concluded_by
 * @property string|null $remarks
 */
class DpcCandidateCall extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proceeding_id',
        'dpc_evaluation_row_id',
        'called_at',
        'called_by',
        'concluded_at',
        'concluded_by',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'concluded_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function isCalled(): bool
    {
        return $this->called_at !== null;
    }

    public function isConcluded(): bool
    {
        return $this->concluded_at !== null;
    }

    /**
     * @return BelongsTo<DpcProceeding, $this>
     */
    public function proceeding(): BelongsTo
    {
        return $this->belongsTo(DpcProceeding::class, 'dpc_proceeding_id');
    }

    /**
     * @return BelongsTo<DpcEvaluationRow, $this>
     */
    public function evaluationRow(): BelongsTo
    {
        return $this->belongsTo(DpcEvaluationRow::class, 'dpc_evaluation_row_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function concluder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluded_by');
    }
}
