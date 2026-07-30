<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Models;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\Signature\Models\Signature;
use App\Support\Concerns\Blameable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $department_id
 * @property int $version
 * @property int|null $parent_version_id
 * @property bool $is_current
 * @property PreDpcStatus $status
 * @property string|null $government_order_number
 * @property Carbon|null $effective_from
 * @property int|null $prepared_by
 * @property Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $signature_id
 * @property Carbon|null $returned_at
 * @property string|null $return_remarks
 * @property Carbon|null $cancelled_at
 */
class PreDpcProposal extends Model implements Auditable
{
    use AuditableTrait;
    use Blameable;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'version',
        'parent_version_id',
        'is_current',
        'status',
        'government_order_number',
        'effective_from',
        'prepared_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'signature_id',
        'returned_at',
        'return_remarks',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_current' => 'boolean',
            'status' => PreDpcStatus::class,
            'effective_from' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
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
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<PreDpcPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(PreDpcPost::class)->orderBy('sort_order');
    }
}
