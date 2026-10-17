<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $min_members
 * @property int $max_members
 * @property list<string>|null $required_categories
 * @property bool $requires_reserved_category
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
        'min_members',
        'max_members',
        'required_categories',
        'requires_reserved_category',
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
            'required_categories' => 'array',
            'requires_reserved_category' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
