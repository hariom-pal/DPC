<?php

declare(strict_types=1);

namespace App\Domain\PreDpc\Support;

use App\Domain\Master\Models\Cadre;
use App\Domain\PreDpc\Models\PreDpcPostFeeder;
use App\Domain\PreDpc\Services\PreDpcProposalService;

/**
 * Which Cadre master row a Pre-DPC feeder means.
 *
 * **One statement of the rule, asked by two callers.** The projection
 * ({@see PreDpcProposalService}) asks it to build a
 * recruitment rule; `pre-dpc:unmatched-feeders` asks it to report the feeders
 * that resolve to nothing. If the report derived the answer separately it would
 * eventually disagree with the projection, and a report that disagrees with the
 * thing it reports on is worse than none — the same argument as TD-20, where two
 * copies of one query is how one of them quietly stops matching the other.
 *
 * **Two paths, and the order matters.** A feeder chosen from the master carries
 * `feeder_cadre_id` and is that row — nothing is matched or guessed. A feeder
 * that carries only `feeder_name` is matched by name within the department, which
 * is how proposals drafted before 2026-08-18 and any caller posting text alone
 * still work.
 *
 * **A name that matches nothing returns null, and that is a finding, not an
 * error.** Silently dropping such a feeder is TD-25: the recruitment rule is then
 * created without it, so the rule looks complete and sanctions nothing.
 */
class FeederCadreResolver
{
    public function resolve(PreDpcPostFeeder $feeder, int $departmentId): ?Cadre
    {
        if ($feeder->feeder_cadre_id !== null) {
            return Cadre::query()->find($feeder->feeder_cadre_id);
        }

        return Cadre::query()
            ->where('department_id', $departmentId)
            ->where('name', $feeder->feeder_name)
            ->first();
    }
}
