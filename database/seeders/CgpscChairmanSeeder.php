<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Committee\Models\CgpscChairman;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The serving CGPSC Chairman: the master record only (business decisions of
 * 2026-10-26 and 2026-10-27).
 *
 * Every DPC committee seats the serving Chairman, and `DpcCommitteeService` refuses
 * to compose one without a record, so a fresh installation needs the record. It is
 * reference data, not an event: it is written directly rather than through
 * `CgpscChairmanService::appoint()`, which records a `cgpsc_chairman.appointed`
 * timeline entry for an appointment an Administrator makes. A fresh installation
 * therefore carries no workflow or timeline record (FreshInstallationTest), while
 * every real appointment is still recorded.
 *
 * No portal account and no password are seeded: the Portal Super Admin creates the
 * Chairman's account in User Management and links it under Administration → CGPSC
 * Chairman.
 *
 * Idempotent: an already serving Chairman is left in office.
 */
class CgpscChairmanSeeder extends Seeder
{
    private const DESIGNATION = 'Chairman, Chhattisgarh Public Service Commission';

    public function run(): void
    {
        if (CgpscChairman::query()->serving()->exists()) {
            return;
        }

        $chairman = new CgpscChairman([
            'user_id' => null,
            'name' => (string) config('dpc.chairman.name'),
            'designation' => self::DESIGNATION,
            'email' => null,
            'mobile' => null,
        ]);
        $chairman->effective_from = Carbon::today();
        $chairman->forceFill(['active_flag' => 1]);
        $chairman->save();
    }
}
