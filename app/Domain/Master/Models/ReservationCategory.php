<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property string $code
 * @property string $name_en
 * @property string $name_hi
 * @property bool $is_reserved
 * @property MasterStatus $status
 */
class ReservationCategory extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name_en',
        'name_hi',
        'is_reserved',
    ];

    public function initializeReservationCategory(): void
    {
        $this->mergeCasts(['is_reserved' => 'boolean']);
    }
}
