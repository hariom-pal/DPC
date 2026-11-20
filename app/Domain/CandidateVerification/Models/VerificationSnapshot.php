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

    /**
     * The hash of a snapshot, reproducible from the stored copy. MySQL's JSON column
     * reorders object keys, so a hash of the encoding as built could never be checked
     * against what the database holds: object keys are sorted first, list order kept.
     *
     * @param  array<array-key, mixed>  $snapshot
     */
    public static function hashOf(array $snapshot): string
    {
        return hash('sha256', (string) json_encode(self::canonical($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
