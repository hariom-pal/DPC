<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcIdSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Race-safe, per-department, per-year serial numbers — for both the DPC Number
 * and the Pre-DPC Number.
 *
 * **One generator, two series** (change item 10). Both identifiers are the same
 * mechanism: take the department's counter for this year under a row lock, add
 * one, format. Writing that twice is how the second copy comes to differ from the
 * first in exactly the situation it exists for — two approvals arriving in the
 * same millisecond. The `series` column on `dpc_id_sequences` is the
 * discriminator; see the migration that added it.
 *
 * **The lock is what the class is for.** `lockForUpdate()` inside a transaction
 * means a concurrent caller waits rather than reading the same `last_serial`.
 * Without it, two approvals are assigned the same number and the unique index
 * refuses the second — at the moment an Administrative Head presses Approve, with
 * a database error rather than an explanation.
 */
class IdentifierSequenceService
{
    /**
     * The DPC Number series.
     *
     * Format changed on 2026-08-24 (change items 18 and 19): `DPC-2026-HED-0001`
     * rather than the old `HE201711111`. The register's examples are explicit, and
     * the new form is legible aloud — which matters for an identifier that appears
     * on a signed order and gets read down a telephone.
     */
    public const SERIES_DPC = 'dpc';

    /**
     * The Pre-DPC Number series: `PDPC-2026-HED-0001` (change item 10).
     */
    public const SERIES_PRE_DPC = 'pre_dpc';

    /**
     * The prefix each series wears.
     */
    private const PREFIXES = [
        self::SERIES_DPC => 'DPC',
        self::SERIES_PRE_DPC => 'PDPC',
    ];

    /**
     * The next identifier in `$series` for this department and year.
     *
     * `$year` is the **DPC Year** rather than today's date. The two differ
     * routinely: a 2026 exercise approved in January 2027 belongs to the 2026
     * series, and numbering it 2027 would file it under the wrong year for every
     * report that groups by it.
     */
    public function next(Department $department, string $series, int $year): string
    {
        $serial = DB::transaction(function () use ($department, $series, $year): int {
            $sequence = $this->locked($department->id, $series, $year);

            if ($sequence === null) {
                try {
                    $sequence = DpcIdSequence::query()->create([
                        'department_id' => $department->id,
                        'series' => $series,
                        'year' => $year,
                        'last_serial' => 0,
                    ]);
                } catch (QueryException) {
                    // Another concurrent approval won the race to create this
                    // (department, series, year) row first — re-fetch under lock.
                    $sequence = $this->locked($department->id, $series, $year);
                }
            }

            if ($sequence === null) {
                // Unreachable in practice: the insert either succeeded or lost a
                // race to a row that now exists. Stated rather than assumed,
                // because silently returning serial 0 would issue a duplicate.
                throw new \RuntimeException(sprintf(
                    'Could not obtain the %s counter for department %d, year %d.',
                    $series,
                    $department->id,
                    $year,
                ));
            }

            $sequence->increment('last_serial');

            return (int) $sequence->last_serial;
        });

        return $this->format($series, $department->code, $year, $serial);
    }

    private function locked(int $departmentId, string $series, int $year): ?DpcIdSequence
    {
        return DpcIdSequence::query()
            ->where('department_id', $departmentId)
            ->where('series', $series)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();
    }

    /**
     * `<PREFIX>-<year>-<DEPT>-<serial>`.
     *
     * Width is configuration, never hardcoded: a department that issues more than
     * 9,999 DPCs in a year needs a wider serial and not a deployment.
     */
    private function format(string $series, string $departmentCode, int $year, int $serial): string
    {
        $width = (int) config('dpc.dpc_id.serial_width', 4);
        $prefix = self::PREFIXES[$series] ?? strtoupper($series);

        return sprintf('%s-%d-%s-%s', $prefix, $year, $departmentCode, str_pad((string) $serial, $width, '0', STR_PAD_LEFT));
    }
}
