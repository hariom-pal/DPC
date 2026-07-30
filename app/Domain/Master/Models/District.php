<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property string|null $lgd_code
 * @property string $state_lgd_code
 * @property string $name_en
 * @property string $name_hi
 * @property MasterStatus $status
 */
class District extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lgd_code',
        'state_lgd_code',
        'name_en',
        'name_hi',
    ];
}
