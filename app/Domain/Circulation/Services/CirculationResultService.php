<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Services;

use App\Domain\Circulation\Enums\CirculationDecision;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use Illuminate\Support\Collection;

/**
 * S48's before-and-after — M9 Phase 2.
 *
 * S48 shows the same result table twice: once with `ST SS CIR` against the
 * held officer, once with `ST SS`. That pairing is the screen's meaning —
 * *this is what the DPC decided, and this is what it decides once the reserved
 * action is released.*
 *
 * **Both tables are the same stored result.** Nothing here calculates. The
 * only difference between "before" and "after" is whether the `CIR`
 * annotation is present, and that annotation is derived from **hold state**,
 * never from a second calculation.
 *
 * **AD-23 is the reason this class exists at all.** The obvious way to render
 * "after" would be to recalculate with the hold cleared — and that would
 * change marks, could create a Super Seat, and could move officers who are not
 * party to the circulation and have no notice of it. So the result is read as
 * **data**: `DpcCalculationRun` and `DpcCalculationResult` as models, from the
 * run the proceeding pinned at approval. Reading a stored decision is not
 * making one.
 */
class CirculationResultService
{
    /**
     * The run the origin's proceeding pinned when its result was approved.
     *
     * Read from the proceeding's own column rather than by asking a
     * calculation service for the latest run: the pinned run is the one the
     * promotion rests on, and "latest" is not the same thing.
     */
    public function approvedRun(DpcCirculation $circulation): ?DpcCalculationRun
    {
        $proceeding = $circulation->originProceeding;

        if ($proceeding === null) {
            return null;
        }

        $runId = $proceeding->final_calculation_run_id ?? $proceeding->latest_calculation_run_id;

        if ($runId === null) {
            return null;
        }

        return DpcCalculationRun::query()->find($runId);
    }

    /**
     * The origin's approved result, each row annotated with what this
     * circulation does — and does not — change about it.
     *
     * Every row carries `held_before` and `held_after`. **For every officer
     * not on this note both are identical**, which is the property S48 is
     * really asserting and the one a reader should be able to check at a
     * glance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function beforeAndAfter(DpcCirculation $circulation): Collection
    {
        $run = $this->approvedRun($circulation);

        if ($run === null) {
            return collect();
        }

        // Hold state as it stands now — the source of the `CIR` annotation.
        $heldNow = DpcProceedingHold::query()
            ->where('dpc_proceeding_id', $circulation->origin_dpc_proceeding_id)
            ->whereNull('released_at')
            ->pluck('sn')
            ->unique();

        // The officers this note would release, if it is signed: those whose
        // item the Commission has consented to. A returned item releases
        // nothing, which is the whole point of recording the decision.
        $wouldRelease = $circulation->items
            ->filter(static fn ($item): bool => $item->decision === CirculationDecision::Consented)
            ->pluck('sn')
            ->unique();

        // Precomputed rather than reached for inside the closure: the map is
        // static and must not capture the aggregate.
        $onThisNote = $circulation->items->pluck('sn')->unique();

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        $results = DpcCalculationResult::query()
            ->where('dpc_calculation_run_id', $run->id)
            ->orderBy('list_type')
            ->orderBy('list_position')
            ->orderBy('sn')
            ->get();

        foreach ($results as $result) {
            $heldBefore = $heldNow->contains($result->sn);

            $rows[] = [
                'sn' => $result->sn,
                'officer_name' => $result->officer_name,
                'category' => $result->reservation_category_code,
                // The mark is the stored one. It is identical in both
                // columns, and no code path here can change it.
                'remark' => $result->remark,
                'list_type' => $result->list_type,
                'list_position' => $result->list_position,
                'held_before' => $heldBefore,
                'held_after' => $heldBefore && ! $wouldRelease->contains($result->sn),
                'on_this_note' => $onThisNote->contains($result->sn),
            ];
        }

        return collect($rows);
    }
}
