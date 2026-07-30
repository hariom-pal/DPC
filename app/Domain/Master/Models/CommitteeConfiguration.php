<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $committee_type_id
 * @property int $min_members
 * @property int $max_members
 * @property bool $chairman_mandatory
 * @property int $quorum
 * @property string $voting_method
 * @property string $majority_type
 * @property string $tie_break_rule
 * @property bool $digital_signature_required
 * @property bool $remarks_required
 * @property bool $external_member_allowed
 * @property bool $secretary_required
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property MasterStatus $status
 */
class CommitteeConfiguration extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'committee_type_id',
        'min_members',
        'max_members',
        'chairman_mandatory',
        'quorum',
        'voting_method',
        'majority_type',
        'tie_break_rule',
        'digital_signature_required',
        'remarks_required',
        'external_member_allowed',
        'secretary_required',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_members' => 'integer',
            'max_members' => 'integer',
            'quorum' => 'integer',
            'chairman_mandatory' => 'boolean',
            'digital_signature_required' => 'boolean',
            'remarks_required' => 'boolean',
            'external_member_allowed' => 'boolean',
            'secretary_required' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<CommitteeType, $this>
     */
    public function committeeType(): BelongsTo
    {
        return $this->belongsTo(CommitteeType::class);
    }
}
