<?php

declare(strict_types=1);

namespace App\Domain\Review\Models;

use App\Domain\Proceeding\Models\DpcProceedingDocument;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * The review references a generated document carries — M8 Phase 3.
 *
 * Kept beside the document rather than on it, because `dpc_proceeding_documents`
 * belongs to M7 and writing there would mean editing a frozen model or slipping
 * past its immutability guard.
 *
 * **Append-only.** Only `supersession_effective_at` may ever move, and only
 * once — from null to a timestamp. Which document belongs to which chain never
 * changes.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proceeding_document_id
 * @property string $document_uuid
 * @property string $original_dpc_uuid
 * @property string $review_dpc_uuid
 * @property string $chain_uuid
 * @property int $review_number
 * @property string|null $review_dpc_code
 * @property Carbon|null $supersession_effective_at
 */
class DpcDocumentReviewReference extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    protected $table = 'dpc_document_review_references';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proceeding_document_id',
        'document_uuid',
        'original_dpc_uuid',
        'review_dpc_uuid',
        'chain_uuid',
        'review_number',
        'review_dpc_code',
        'supersession_effective_at',
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
            'review_number' => 'integer',
            'supersession_effective_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $reference): void {
            if (array_diff(array_keys($reference->getDirty()), ['supersession_effective_at', 'updated_at']) !== []) {
                throw new RuntimeException(
                    'A document review reference is permanent. Which document belongs to which chain '
                    .'cannot be rewritten.'
                );
            }

            if ($reference->getOriginal('supersession_effective_at') !== null) {
                throw new RuntimeException('A supersession timestamp cannot be changed once recorded.');
            }
        });

        static::deleting(function (self $reference): void {
            throw new RuntimeException('Document review references are permanent and cannot be deleted.');
        });
    }

    /**
     * @return list<string>
     */
    public function generateTags(): array
    {
        return [$this->uuid, $this->chain_uuid, $this->document_uuid];
    }

    /**
     * @return BelongsTo<DpcProceedingDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(DpcProceedingDocument::class, 'dpc_proceeding_document_id');
    }

    public function isSuperseded(): bool
    {
        return $this->supersession_effective_at !== null;
    }
}
