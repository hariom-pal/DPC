<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Master\Concerns\IsMaster;
use App\Enums\MasterStatus;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property string $label
 * @property string|null $code
 * @property int|null $level
 * @property int|null $grade_pay
 * @property MasterStatus $status
 */
class PayScale extends Model implements Auditable
{
    use IsMaster;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'label',
        'code',

        /*
         | What makes a pay scale *comparable* (change register of 2026-08-27,
         | item 11).
         |
         | A label and a code cannot answer "is this scale higher than that one",
         | which is what the promotion hierarchy check needs. `level` is the ordinal
         | — higher means senior — and `grade_pay` is the figure the register names
         | in its own right.
         |
         | Both nullable, and the hierarchy check **skips a missing value rather
         | than failing on it**: a scale registered before this change has neither,
         | and a master-data gap is not a promotion rule. Refusing every promotion
         | out of an unnumbered scale would make an incomplete master look like an
         | ineligible officer.
         */
        'level',
        'grade_pay',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'grade_pay' => 'integer',
        ];
    }
}
