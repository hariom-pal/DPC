<?php

declare(strict_types=1);

namespace App\Domain\Master\Concerns;

use App\Enums\MasterStatus;
use App\Support\Concerns\Blameable;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;

/**
 * Shared behaviour for reference masters: field-level audit (owen-it),
 * blameable audit columns, soft deletes, and the MasterStatus lifecycle.
 * The consuming model must `implements OwenIt\Auditing\Contracts\Auditable`
 * and its table must define status + created_by/updated_by/deleted_by.
 */
trait IsMaster
{
    use AuditableTrait;
    use Blameable;
    use SoftDeletes;

    public function initializeIsMaster(): void
    {
        $this->mergeFillable(['status', 'created_by', 'updated_by', 'deleted_by']);
        $this->mergeCasts(['status' => MasterStatus::class]);
    }
}
