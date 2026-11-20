<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One message of the verification, and what became of it: queued, sent, failed, or
 * skipped for want of a number or address. Queued is never reported as delivered.
 *
 * @property int $id
 * @property int $dpc_verification_cycle_id
 * @property string $kind
 * @property string $channel
 * @property string $recipient
 * @property string $reference
 * @property string|null $destination
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 */
class VerificationNotification extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $table = 'dpc_verification_notifications';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'attempts' => 'integer'];
    }
}
