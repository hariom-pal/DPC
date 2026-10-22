<?php

declare(strict_types=1);

namespace App\Domain\PromotionOrder\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Signature\Models\Signature;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A promotion order (Coder Instructions rule 4.3): issued by the Administrative Head
 * after the CGPSC's result, a signed PDF of the officers promoted, with the order date
 * seniority runs from. Never rewritten; a correction supersedes it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $dpc_proposal_id
 * @property int $dpc_proceeding_id
 * @property string $order_number
 * @property Carbon $order_date
 * @property int $version
 * @property int|null $supersedes_id
 * @property string $status
 * @property Carbon|null $superseded_at
 * @property list<array{sn: int, name: string, employee_number: string|null, category: string|null, list_position: int|null}> $officers
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $content_hash
 * @property int|null $signature_id
 * @property string|null $failure_reason
 * @property int|null $issued_by
 * @property Carbon $issued_at
 * @property Carbon|null $notified_at
 */
class DpcPromotionOrder extends Model
{
    use HasUuids;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_proposal_id', 'dpc_proceeding_id', 'order_number', 'order_date', 'version', 'supersedes_id',
        'status', 'superseded_at', 'officers', 'disk', 'path', 'content_hash', 'signature_id',
        'failure_reason', 'issued_by', 'issued_at', 'notified_at',
    ];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** Addressed by UUID in a URL, never by its sequential id (AD-8). */
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
            'order_date' => 'date',
            'version' => 'integer',
            'superseded_at' => 'datetime',
            'officers' => 'array',
            'issued_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $order): void {
            // What the order says — its number, date, officers and version — is fixed.
            // Only its rendering, its supersession and its notification move.
            $permitted = [
                'status', 'superseded_at', 'disk', 'path', 'content_hash', 'signature_id',
                'failure_reason', 'notified_at', 'updated_at',
            ];

            if (array_diff(array_keys($order->getDirty()), $permitted) !== []) {
                throw new RuntimeException('An issued promotion order is never rewritten; issue a new order superseding it.');
            }

            if ($order->getOriginal('content_hash') !== null && $order->isDirty('content_hash')) {
                throw new RuntimeException('The contents of an issued promotion order cannot be replaced.');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Promotion orders are permanent records and cannot be deleted.');
        });
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED && $this->content_hash !== null;
    }

    /**
     * @return BelongsTo<DpcProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(DpcProposal::class, 'dpc_proposal_id');
    }

    /**
     * @return BelongsTo<DpcProceeding, $this>
     */
    public function proceeding(): BelongsTo
    {
        return $this->belongsTo(DpcProceeding::class, 'dpc_proceeding_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return BelongsTo<Signature, $this>
     */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class);
    }
}
