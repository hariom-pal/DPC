<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An Eligible CR Grade: a numeric value a CR may carry, valid from a date.
 *
 * @property int $id
 * @property int $value
 * @property Carbon $effective_from
 * @property string|null $remarks
 * @property int|null $created_by
 */
class CrGradeValue extends Model implements Auditable
{
    use AuditableTrait;

    protected $fillable = ['value', 'effective_from', 'remarks', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'integer', 'effective_from' => 'date'];
    }
}
