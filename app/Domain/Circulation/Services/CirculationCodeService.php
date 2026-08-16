<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Services;

use App\Domain\Circulation\Models\DpcCirculation;

/**
 * The circulation note's own identifier — M9 Phase 1.
 *
 * **Deliberately not a DPC ID, and deliberately not minted from
 * `dpc_id_sequences`.** AD-24 rules that a Circulation is not a DPC and must
 * never appear in any record to have been one. Drawing from M4's sequence
 * would consume DPC ID serials for something that is not a DPC and would
 * produce a code indistinguishable from one — a promotion list, a report or a
 * tribunal bundle would then show `HE201700003` with no way to tell what it
 * was.
 *
 * The form is **`<origin DPC ID>/C<n>`** — `HE201711111/C1`. It states on its
 * face what the note is (a circulation), and which DPC it belongs to, which is
 * the pair a reader actually needs. The ordinal is per-origin, so the code
 * leaks nothing about how many circulations exist anywhere else.
 *
 * `circulation_code` carries a unique index, which is the real guarantee: if
 * two approvals on the same origin ever raced past the check below, the
 * second insert fails and its transaction rolls back rather than issuing a
 * duplicate identifier.
 */
class CirculationCodeService
{
    public function next(DpcCirculation $circulation): string
    {
        $base = $circulation->origin_dpc_code !== null && $circulation->origin_dpc_code !== ''
            ? $circulation->origin_dpc_code
            : 'DPC'.$circulation->origin_dpc_proposal_id;

        $ordinal = DpcCirculation::query()
            ->where('origin_dpc_proposal_id', $circulation->origin_dpc_proposal_id)
            ->whereNotNull('circulation_code')
            ->count() + 1;

        do {
            $candidate = "{$base}/C{$ordinal}";
            $taken = DpcCirculation::query()->where('circulation_code', $candidate)->exists();
            $ordinal++;
        } while ($taken);

        return $candidate;
    }
}
