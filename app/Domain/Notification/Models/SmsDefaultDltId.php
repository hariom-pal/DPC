<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The DLT Content Template ID the CGPSC Administrator registered for one SMS's
 * System default text (2026-11-13). The text stays in the application; only its ID
 * is kept here. Every change is audited.
 *
 * @property int $id
 * @property string $key
 * @property string $dlt_template_id
 * @property int|null $updated_by
 */
class SmsDefaultDltId extends Model implements Auditable
{
    use AuditableTrait;

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'dlt_template_id', 'updated_by'];
}
