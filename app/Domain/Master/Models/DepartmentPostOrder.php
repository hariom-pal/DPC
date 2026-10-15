<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One Government Order bearing on a Department Post Mapping (change register of
 * 2026-08-26, item 10).
 *
 * > *Support multiple Government Orders. Each Government Order entry should
 * > contain Government Order / Circular Number, Government Order Date, PDF Upload.
 * > Provide Add More. Previous Government Orders must remain available for audit.*
 *
 * **This replaces `DepartmentPostDocument`, which was the wrong unit.** That model
 * held *versions of one PDF* and enforced "one current document" — so a mapping
 * amended by a corrigendum could only record the corrigendum by retiring the
 * original order's scan. The number lived on the mapping and the file lived here,
 * and there could be one of each. A row is now a whole order: its number, its date,
 * and its own PDF.
 *
 * **Nothing here is ever edited or deleted**, and the model enforces it rather than
 * trusting every future caller to remember. An order is the legal reference for a
 * mapping; one that could be quietly amended would take the evidence of what was
 * relied on with it. Superseding a *mapping* is a different operation and lives on
 * `department_posts` — see `effective_from`/`effective_to`/`active_flag` there.
 *
 * **The PDF is optional, deliberately.** A department may know an order's number
 * and date before the scan reaches them, and refusing the citation until the file
 * arrives would mean the portal holds less than the department does. Ask
 * {@see hasDocument()} rather than testing `path` for null.
 *
 * @property int $id
 * @property string $uuid
 * @property int $department_post_id
 * @property string $government_order_number
 * @property Carbon $government_order_date
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $original_name
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property int $sort_order
 * @property int|null $uploaded_by
 */
class DepartmentPostOrder extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

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
     * @var list<string>
     */
    protected $fillable = [
        'department_post_id',
        'government_order_number',
        'government_order_date',
        'disk',
        'path',
        'original_name',
        'size_bytes',
        'sha256',
        'sort_order',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'government_order_date' => 'date',
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The only column an update may touch.
     *
     * `sort_order` alone, because the order the instruments are listed in is
     * presentation and the instruments themselves are evidence. Everything that
     * says *what the order is* — its number, its date, its file, its hash — is
     * fixed at the moment it is recorded.
     */
    private const PERMITTED_UPDATES = ['sort_order', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $order): void {
            $forbidden = array_diff(array_keys($order->getDirty()), self::PERMITTED_UPDATES);

            if ($forbidden !== []) {
                throw new LogicException(sprintf(
                    'A Government Order is evidence and is not edited: attempted to change %s. '
                    .'Record a further order instead — the previous ones are kept.',
                    implode(', ', $forbidden),
                ));
            }
        });

        static::deleting(function (): void {
            throw new LogicException(
                'A Government Order is never deleted. Record a further order instead; the previous ones are kept.'
            );
        });
    }

    /**
     * @return BelongsTo<DepartmentPost, $this>
     */
    public function departmentPost(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function hasDocument(): bool
    {
        return $this->path !== null && $this->disk !== null;
    }

    /**
     * Whether the stored file still matches the hash taken when it was received.
     *
     * A reference nobody can verify is not a reference, so the screen states this
     * rather than assuming it. An order with no file is **not** "intact" and not
     * "corrupt" either — the caller asks {@see hasDocument()} first.
     */
    public function isIntact(): bool
    {
        if (! $this->hasDocument()) {
            return false;
        }

        $disk = Storage::disk((string) $this->disk);

        if (! $disk->exists((string) $this->path)) {
            return false;
        }

        return hash('sha256', (string) $disk->get((string) $this->path)) === $this->sha256;
    }

    /**
     * How this order reads in a citation — "F-3/2026/1-3 (14-03-2026)".
     */
    public function citation(): string
    {
        return sprintf('%s (%s)', $this->government_order_number, $this->government_order_date->format('d-m-Y'));
    }

    /**
     * The size, for a human.
     */
    public function sizeLabel(): string
    {
        $bytes = (int) $this->size_bytes;

        return $bytes < 1024 * 1024
            ? number_format($bytes / 1024, 0).' KB'
            : number_format($bytes / (1024 * 1024), 1).' MB';
    }
}
