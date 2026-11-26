<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A result document of a DPC (2026-11-25): its Hindi proceedings (कार्यवाही विवरण), or
 * one officer's confidential sealed-cover recommendation. Made as a PDF when the result
 * is approved, from the snapshot in `data`; once every committee member has eSigned,
 * made again with their signatures on it (`final_path`) and final.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proceeding_id
 * @property string $kind karyavahi | sealed_cover
 * @property string|null $format class_1_to_1 | other
 * @property int|null $dpc_calculation_result_id
 * @property int|null $sn
 * @property string|null $officer_name
 * @property array<string, mixed> $data
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property Carbon $generated_at
 * @property int|null $generated_by
 * @property string|null $final_path
 * @property string|null $final_sha256
 * @property Carbon|null $finalized_at
 */
class DpcResultDocument extends Model
{
    use HasUuids;

    public const KARYAVAHI = 'karyavahi';

    public const SEALED_COVER = 'sealed_cover';

    public const CLASS_ONE_TO_ONE = 'class_1_to_1';

    public const OTHER = 'other';

    /** @var list<string> */
    protected $fillable = [
        'uuid', 'dpc_proceeding_id', 'kind', 'format', 'dpc_calculation_result_id', 'sn', 'officer_name', 'data',
        'disk', 'path', 'sha256', 'generated_at', 'generated_by', 'final_path', 'final_sha256', 'finalized_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data' => 'array', 'generated_at' => 'datetime', 'finalized_at' => 'datetime'];
    }

    /**
     * A result document never changes once made (2026-11-26): its snapshot, its file and
     * its digest are what every member signed. The one change it ever takes is being made
     * final — its signed copy recorded, once — and nothing after that; it is never deleted.
     */
    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            $allowed = ['final_path', 'final_sha256', 'finalized_at', 'updated_at'];
            $changed = array_keys($document->getDirty());

            if ($document->getOriginal('finalized_at') !== null
                || $document->getOriginal('final_path') !== null
                || array_diff($changed, $allowed) !== []) {
                throw new RuntimeException('A result document is a permanent record: only its signed copy is added, once.');
            }
        });

        static::deleting(function (): never {
            throw new RuntimeException('A result document is a permanent record and is never deleted.');
        });
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<DpcProceeding, $this> */
    public function proceeding(): BelongsTo
    {
        return $this->belongsTo(DpcProceeding::class, 'dpc_proceeding_id');
    }

    /** The file name a download carries. */
    public function filename(bool $final): string
    {
        $base = $this->kind === self::KARYAVAHI ? 'dpc-karyavahi-vivaran' : 'sealed-cover-sn-'.$this->sn;

        return $base.($final ? '-signed' : '').'.pdf';
    }
}
