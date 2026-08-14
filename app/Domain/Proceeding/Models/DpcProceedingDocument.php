<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Proceeding\Enums\DocumentStatus;
use App\Domain\Proceeding\Enums\DocumentType;
use App\Domain\Signature\Models\Signature;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use RuntimeException;

/**
 * A generated proceedings document or promotion list — M7 Phase 4.
 *
 * **Generated once, never regenerated in place.** A template improvement does
 * not reach a document already issued, which is correct for evidence: the
 * artefact a department was given must not change afterwards. Re-issuing
 * creates a **new row with a higher `document_version`**; the previous one
 * stands.
 *
 * Carries its own UUID, a document version, a generator version and a
 * generation timestamp (architect Directive 3, Phase 1), plus the three
 * permanent references an approved result must keep — the proceeding, the
 * final calculation and the proposal (Directive 2, Phase 4). They are stored
 * as UUID strings rather than foreign keys because a legal document must stay
 * verifiable without depending on a join surviving.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proceeding_id
 * @property DocumentType $type
 * @property DocumentStatus $status
 * @property string $proceeding_uuid
 * @property string|null $final_calculation_uuid
 * @property string $proposal_uuid
 * @property int $document_version
 * @property string $generator_version
 * @property string|null $generator_commit_hash
 * @property string|null $calculation_engine_version
 * @property string|null $rule_version
 * @property Carbon|null $generated_at
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $content_hash
 * @property int|null $signature_id
 * @property Carbon|null $published_at
 * @property string|null $failure_reason
 * @property int|null $generated_by
 * @property string|null $generated_by_name
 */
class DpcProceedingDocument extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proceeding_id',
        'type',
        'status',
        'proceeding_uuid',
        'final_calculation_uuid',
        'proposal_uuid',
        'document_version',
        'generator_version',
        'generator_commit_hash',
        'calculation_engine_version',
        'rule_version',
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
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'document_version' => 'integer',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            // Only the generation and release lifecycle may move. What the
            // document *is* — which proceeding, which calculation, which
            // proposal, which version, and the hash of its contents — is fixed
            // the moment it is generated.
            $permitted = [
                'status', 'disk', 'path', 'content_hash', 'signature_id',
                'generated_at', 'generated_by', 'generated_by_name',
                'published_at', 'failure_reason', 'updated_at',
            ];

            if (array_diff(array_keys($document->getDirty()), $permitted) !== []) {
                throw new RuntimeException(
                    'A generated document is a permanent record. Re-issue it as a new version instead; '
                    .'an issued document is never rewritten.'
                );
            }

            // Once the contents are hashed, they are the contents. Re-issuing
            // means a new row, not a new hash on this one.
            if ($document->getOriginal('content_hash') !== null && $document->isDirty('content_hash')) {
                throw new RuntimeException('The contents of an issued document cannot be replaced.');
            }
        });

        static::deleting(function (self $document): void {
            throw new RuntimeException('Documents are permanent records and cannot be deleted.');
        });
    }

    /**
     * @return list<string>
     */
    public function generateTags(): array
    {
        return array_values(array_filter([$this->uuid, $this->proceeding_uuid]));
    }

    /**
     * @return BelongsTo<DpcProceeding, $this>
     */
    public function proceeding(): BelongsTo
    {
        return $this->belongsTo(DpcProceeding::class, 'dpc_proceeding_id');
    }

    /**
     * @return BelongsTo<Signature, $this>
     */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class, 'signature_id');
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
        return $this->status === DocumentStatus::Generated
            && $this->disk !== null
            && $this->path !== null;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
