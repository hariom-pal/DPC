<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A version of what a candidate was shown: version 1 the one they signed, later ones
 * the record as corrected after a grievance. Never rewritten.
 *
 * @property int $id
 * @property int $dpc_verification_participant_id
 * @property int $version
 * @property string $reason
 * @property int|null $dpc_grievance_id
 * @property array<string, mixed> $snapshot
 * @property string $sha256
 * @property int|null $created_by
 */
class VerificationSnapshot extends Model
{
    protected $table = 'dpc_verification_snapshots';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer'];
    }
}
