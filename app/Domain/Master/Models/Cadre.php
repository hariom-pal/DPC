<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $department_id
 * @property string $name
 * @property ServiceClass $class_level
 * @property int|null $pay_scale_id
 * @property MasterStatus $status
 */
class Cadre extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'name',
        'class_level',
        'pay_scale_id',
    ];

    public function initializeCadre(): void
    {
        $this->mergeCasts(['class_level' => ServiceClass::class]);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<PayScale, $this>
     */
    public function payScale(): BelongsTo
    {
        return $this->belongsTo(PayScale::class);
    }
}
