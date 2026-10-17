<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Models;

use App\Domain\Authentication\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One version of the PH (Persons with Disabilities) reservation in promotion
 * (rule 2.1), from a DPC year onwards, on the order that authorised it.
 *
 * The reservation is a percentage of the promotion vacancies, computed as a whole
 * number by the rounding stated here (the rule: the decimal part is disregarded even
 * if it is .9, i.e. `floor`). "Once in service" and the undertaking are the rule's
 * own terms, not settings, and stay in the PH questionnaire. Never edited: a change
 * is a new version.
 *
 * @property int $id
 * @property string $uuid
 * @property int $effective_from_year
 * @property string $percentage
 * @property string $rounding
 * @property string $order_number
 * @property Carbon $order_date
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $remarks
 * @property int|null $recorded_by
 * @property Carbon $recorded_at
 */
class PhReservationRule extends Model implements Auditable
{
    use AuditableTrait;
    use HasUuids;

    public const ROUNDINGS = ['floor', 'round', 'ceil'];

    protected $fillable = [
        'effective_from_year', 'percentage', 'rounding',
        'order_number', 'order_date', 'disk', 'path', 'original_name', 'size_bytes', 'sha256',
        'remarks', 'recorded_by', 'recorded_at',
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
            'effective_from_year' => 'integer',
            'percentage' => 'decimal:2',
            'order_date' => 'date',
            'size_bytes' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
