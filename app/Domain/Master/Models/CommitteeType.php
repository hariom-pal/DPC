<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property string $code
 * @property string $name_en
 * @property string $name_hi
 * @property MasterStatus $status
 */
class CommitteeType extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name_en',
        'name_hi',
    ];

    /**
     * @return HasMany<CommitteeConfiguration, $this>
     */
    public function configurations(): HasMany
    {
        return $this->hasMany(CommitteeConfiguration::class);
    }
}
