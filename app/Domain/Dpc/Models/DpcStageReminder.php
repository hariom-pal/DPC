<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One deadline reminder sent for a DPC's stage (MoM of 16 November 2026, point 6):
 * at most one a day of each kind, and the history kept.
 *
 * @property int $id
 * @property int $dpc_proposal_id
 * @property string $stage
 * @property string $kind 'reminder' (a day or less left) or 'overdue'
 * @property int $recipients
 */
class DpcStageReminder extends Model
{
    protected $fillable = ['dpc_proposal_id', 'stage', 'kind', 'sent_on', 'recipients'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_on' => 'date', 'recipients' => 'integer'];
    }
}
