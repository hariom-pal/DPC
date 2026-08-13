<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcIdSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Race-safe DPC ID generation: <DeptCode><Year><Serial> (PPT S28/S32/S37,
 * e.g. HE201711111). The serial is a per-department, per-year counter
 * (`dpc_id_sequences`), incremented under a row lock inside a transaction so
 * concurrent approvals can never be assigned the same ID.
 */
class DpcIdGeneratorService
{
    public function next(Department $department, Carbon $proposalDate): string
    {
        $year = (int) $proposalDate->format('Y');

        $serial = DB::transaction(function () use ($department, $year): int {
            $sequence = DpcIdSequence::query()
                ->where('department_id', $department->id)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                try {
                    $sequence = DpcIdSequence::query()->create([
                        'department_id' => $department->id,
                        'year' => $year,
                        'last_serial' => 0,
                    ]);
                } catch (QueryException) {
                    // Another concurrent approval won the race to create this
                    // (department, year) row first — re-fetch it under lock.
                    $sequence = DpcIdSequence::query()
                        ->where('department_id', $department->id)
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            $sequence->increment('last_serial');

            return (int) $sequence->last_serial;
        });

        $width = (int) config('dpc.dpc_id.serial_width', 5);

        return $department->code.$year.str_pad((string) $serial, $width, '0', STR_PAD_LEFT);
    }
}
