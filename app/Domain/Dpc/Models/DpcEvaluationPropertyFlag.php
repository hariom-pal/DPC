<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

/**
 * Per-year Immovable Property Details Y/N for an evaluation row (PPT S18,
 * decision Q4) — M4 Phase 5. Shape and casts come from
 * DpcEvaluationYearFlag.
 */
class DpcEvaluationPropertyFlag extends DpcEvaluationYearFlag
{
    protected $table = 'dpc_evaluation_property_flags';
}
