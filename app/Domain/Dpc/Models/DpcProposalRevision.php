<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One revision of a DPC proposal (change item 18).
 *
 * **The row is the record of a round trip.** Revision 1 is opened when the
 * proposal is created; a return closes it with a reason and opens revision 2. The
 * DPC Number is untouched throughout — it is the same proposal being corrected,
 * which is exactly what item 18 says and what a new number would deny.
 *
 * **Previous revisions are read-only, and that is enforced rather than intended.**
 * The register requires it for audit, and a history that the application can edit
 * is not a history. So this model refuses `update` on any revision that is not the
 * current one, and refuses `delete` outright. The guard lives in Eloquent's model
 * events, which means — like every other evidence table here — a query-builder
 * mass update bypasses it; `config('dpc.security.evidence_tables')` is where the
 * database-level grant that closes that gap is declared.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property int $revision_number
 * @property Carbon|null $submitted_at
 * @property int|null $submitted_by
 * @property Carbon|null $returned_at
 * @property int|null $returned_by
 * @property string|null $return_reason
 * @property string|null $remarks
 * @property DpcProposalStatus $status
 */
class DpcProposalRevision extends Model implements Auditable
{
    use AuditableTrait;

    /**
     * The columns a revision may still change while it is the current one.
     *
     * Everything a revision records happens *to* that revision: it is submitted,
     * then it is returned with a reason. Nothing else about it is ever revised —
     * its number and the proposal it belongs to are its identity.
     *
     * @var list<string>
     */
    private const PERMITTED_UPDATES = [
        'submitted_at',
        'submitted_by',
        'returned_at',
        'returned_by',
        'return_reason',
        'remarks',
        'status',
        'updated_at',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id',
        'revision_number',
        'submitted_at',
        'submitted_by',
        'returned_at',
        'returned_by',
        'return_reason',
        'remarks',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'submitted_at' => 'datetime',
            'returned_at' => 'datetime',
            'status' => DpcProposalStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            $changed = array_keys($revision->getDirty());
            $forbidden = array_diff($changed, self::PERMITTED_UPDATES);

            if ($forbidden !== []) {
                throw new LogicException(sprintf(
                    'Revision %d of DPC proposal %d cannot have %s changed. A revision records what happened '
                    .'during it; correcting the proposal opens the next revision instead.',
                    $revision->revision_number,
                    $revision->dpc_proposal_id,
                    implode(', ', $forbidden),
                ));
            }

            // A superseded revision is closed history. Only the current one is
            // still being lived through.
            $current = (int) DpcProposalRevision::query()
                ->where('dpc_proposal_id', $revision->dpc_proposal_id)
                ->max('revision_number');

            if ($revision->revision_number !== $current) {
                throw new LogicException(sprintf(
                    'Revision %d of DPC proposal %d is superseded and read-only. The current revision is %d.',
                    $revision->revision_number,
                    $revision->dpc_proposal_id,
                    $current,
                ));
            }
        });

        static::deleting(function (self $revision): void {
            throw new LogicException(sprintf(
                'Revision %d of DPC proposal %d cannot be deleted. The revision history is what shows a '
                .'tribunal how many times the proposal went back and why.',
                $revision->revision_number,
                $revision->dpc_proposal_id,
            ));
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
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
