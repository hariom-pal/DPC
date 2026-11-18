<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A DPC sitting's meeting, started by its DPC Chairman (2026-11-18).
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property string $room
 * @property string $url
 * @property int $initiated_by
 * @property Carbon $initiated_at
 */
class DpcSittingMeeting extends Model
{
    use HasUuids;

    protected $fillable = ['dpc_proposal_id', 'room', 'url', 'initiated_by', 'initiated_at'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['initiated_at' => 'datetime'];
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
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
