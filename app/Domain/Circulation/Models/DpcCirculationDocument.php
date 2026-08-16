<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Enums\CirculationDocumentStatus;
use App\Domain\Circulation\Enums\CirculationDocumentType;
use App\Domain\Signature\Models\Signature;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * A generated circulation document — M9 Phase 3.
 *
 * **Immutable once generated (AD-4).** The stored bytes and their SHA-256 are
 * what prove a department's copy is the copy the Commission issued; rewriting
 * them would invalidate every copy already in circulation. The guard below
 * permits only the transitions a document legitimately makes — being
 * published, being marked failed — and refuses everything else, including
 * deletion.
 *
 * **A new version is a new row.** Regenerating never overwrites: the previous
 * version stays, which is what makes document history answerable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_circulation_id
 * @property CirculationDocumentType $type
 * @property CirculationDocumentStatus $status
 * @property string $circulation_uuid
 * @property string $origin_proposal_uuid
 * @property string|null $origin_calculation_uuid
 * @property string|null $circulation_code
 * @property string|null $origin_dpc_code
 * @property int $document_version
 * @property string $generator_version
 * @property string|null $generator_commit_hash
 * @property Carbon|null $generated_at
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $content_hash
 * @property int|null $signature_id
 * @property Carbon|null $published_at
 * @property string|null $failure_reason
 */
class DpcCirculationDocument extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_circulation_id',
        'type',
        'status',
        'circulation_uuid',
        'origin_proposal_uuid',
        'origin_calculation_uuid',
        'circulation_code',
        'origin_dpc_code',
        'document_version',
        'generator_version',
        'generator_commit_hash',
        'generated_at',
        'disk',
        'path',
        'content_hash',
        'signature_id',
        'published_at',
        'failure_reason',
        'generated_by',
        'generated_by_name',
    ];

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CirculationDocumentType::class,
            'status' => CirculationDocumentStatus::class,
            'document_version' => 'integer',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            // **The permitted set narrows once the document exists.**
            //
            // While `pending`, generation is still writing: the bytes, the
            // hash and the signature are being recorded for the first time.
            // Once `generated`, all of that is **evidence** — the hash is how
            // a department's copy is shown to be the copy the Commission
            // issued — and the only things a document legitimately does
            // afterwards are get published and, on a failed run, get marked
            // failed (AD-4).
            //
            // Leaving the generation columns writable forever would have made
            // `content_hash` editable on a signed document, which is precisely
            // the guarantee this guard exists to provide.
            $wasGenerated = $document->getOriginal('status') === CirculationDocumentStatus::Generated->value
                || $document->getOriginal('status') === CirculationDocumentStatus::Generated;

            $permitted = $wasGenerated
                ? ['published_at', 'updated_at']
                : [
                    'status', 'disk', 'path', 'content_hash', 'signature_id',
                    'generated_at', 'failure_reason', 'published_at', 'updated_at',
                ];

            $forbidden = array_diff(array_keys($document->getDirty()), $permitted);

            if ($forbidden !== []) {
                throw new RuntimeException(
                    'A generated document is immutable: its bytes, hash, signature and references are what '
                    .'prove a department\'s copy is the copy the Commission issued. Generate a new version '
                    .'instead. Refused: '.implode(', ', $forbidden).'.'
                );
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Generated documents are permanent records and cannot be deleted.');
        });
    }

    /**
     * @return list<string>
     */
    public function generateTags(): array
    {
        return array_values(array_filter([$this->uuid, $this->circulation_uuid]));
    }

    /**
     * @return BelongsTo<DpcCirculation, $this>
     */
    public function circulation(): BelongsTo
    {
        return $this->belongsTo(DpcCirculation::class, 'dpc_circulation_id');
    }

    /**
     * @return BelongsTo<Signature, $this>
     */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function isAvailable(): bool
    {
        return $this->status === CirculationDocumentStatus::Generated
            && $this->path !== null
            && $this->disk !== null;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineProperties(): array
    {
        return [
            'document_uuid' => $this->uuid,
            'type' => $this->type->value,
            'document_version' => $this->document_version,
            'content_hash' => $this->content_hash,
            'generator_version' => $this->generator_version,
            'circulation_code' => $this->circulation_code,
        ];
    }
}
