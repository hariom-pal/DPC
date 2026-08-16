<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Enums\CirculationDecision;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Models\DpcCirculationItem;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Commission's side of a circulation note (PPT S46, S48) — M9 Phase 2.
 *
 * **Three acts, deliberately separate.** The Commission decides each officer
 * (`consent`), may send the note back for more evidence
 * (`return_to_department`), and signs (`sign`). C3 names the signature as what
 * the note is sent *for*, and **the signature is the operative act**: it is
 * where a reserved promotion is released, never at consent and never at the
 * department's approval (AQ-17). A note that is consented but never signed
 * must leave the promotion reserved, for the same reason a Review that is
 * never approved leaves the original DPC standing (AQ-9).
 *
 * **This service performs no DPC execution (AD-23).** It does not calculate,
 * score, determine eligibility or a zone, or conduct a proceeding. It reads
 * the origin's approved result as data and writes exactly one thing to a
 * frozen milestone's table: `dpc_proceeding_holds.released_at`, which M7's own
 * migration declares as M9's seam and whose model guard permits that column
 * and no other (AQ-21). **That is a data write, not a source change.**
 *
 * **`HoldService::release()` is deliberately not used.** It asserts an open
 * proceeding and refuses automatic holds — correct for a sitting in progress,
 * and wrong here: a circulation resolves a hold on a DPC that concluded, which
 * is precisely the case M7 could not serve and left a seam for.
 */
class CirculationCommissionService
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly SignatureService $signatures,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * Record the Commission's decision on one officer.
     *
     * Decisions are recorded **before** the note moves state, and the note
     * only reaches `consented` once every officer has been decided. A note
     * signed with an undecided officer on it would consent to something the
     * Commission never looked at.
     */
    public function decide(
        DpcCirculation $circulation,
        DpcCirculationItem $item,
        User $actor,
        CirculationDecision $decision,
    ): DpcCirculationItem {
        $this->assertWithCommission($circulation);
        $this->assertAllottee($circulation, $actor);

        if ($item->dpc_circulation_id !== $circulation->id) {
            throw new WorkflowException('That officer is not on this circulation.');
        }

        return DB::transaction(function () use ($circulation, $item, $actor, $decision): DpcCirculationItem {
            $item->update([
                'decision' => $decision->value,
                'decided_by' => $actor->id,
                'decided_by_name' => $actor->name,
                'decided_at' => now(),
            ]);

            $this->timeline->record(
                'dpc_circulation.item_decided',
                $circulation->originProposal,
                $decision->label().': '.$item->officer_name,
                array_merge(
                    ['circulation_uuid' => $circulation->uuid],
                    $item->fresh()->toTimelineProperties(),
                ),
                $actor,
            );

            return $item->fresh();
        });
    }

    /**
     * `APPROVE BY CGPSC` on S48 — every officer decided, the note ready to sign.
     */
    public function consent(DpcCirculation $circulation, User $actor, Request $request): DpcCirculation
    {
        return DB::transaction(function () use ($circulation, $actor, $request): DpcCirculation {
            $locked = $this->lock($circulation);
            $this->assertAllottee($locked, $actor);

            if (! $locked->isFullyDecided()) {
                throw new WorkflowException(
                    'Every officer on this circulation must be decided before it can be consented to.'
                );
            }

            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_circulation', 'consent', $from, $actor);

            $locked->forceFill([
                'status' => CirculationStatus::Consented->value,
                'consented_by' => $actor->id,
                'consented_by_name' => $actor->name,
                'consented_at' => now(),
            ])->save();

            $this->record($locked, 'consent', $from, $def, $actor, $request, $this->decisionSummary($locked));

            return $locked->fresh();
        });
    }

    /**
     * Sign the note. **The operative act** — this is where held promotions are
     * released (AQ-17).
     */
    public function sign(DpcCirculation $circulation, User $actor, Request $request): DpcCirculation
    {
        return DB::transaction(function () use ($circulation, $actor, $request): DpcCirculation {
            $locked = $this->lock($circulation);
            $this->assertAllottee($locked, $actor);

            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_circulation', 'sign', $from, $actor);

            $signature = $this->signatures->sign(
                $actor,
                $locked,
                $this->renderForSignature($locked),
                "dpc-circulation-consent-{$locked->id}.txt",
            );

            $released = $this->releaseConsentedHolds($locked, $actor);

            $locked->forceFill([
                'status' => CirculationStatus::Signed->value,
                'signature_id' => $signature->id,
            ])->save();

            $this->record(
                $locked,
                'sign',
                $from,
                $def,
                $actor,
                $request,
                // The remark reaches the notification, and design §15 requires
                // the signed message to name the **officers**, not just the
                // DPC: a department holding a list with `CIR` against two
                // officers needs to know which of them the note released.
                $released === []
                    ? 'No promotion was released; every officer was returned for further evidence.'
                    : 'Promotions now proceeding: '.implode(', ', $released),
                $signature->id,
            );

            return $locked->fresh();
        });
    }

    /**
     * Send the note back to the department for more evidence.
     *
     * **This is not a refusal of the promotion.** S45 describes no power to
     * refuse, and inventing one would be inventing a business rule (BD-12).
     * The hold stands, the officer stays held, and the department may resubmit.
     */
    public function returnToDepartment(
        DpcCirculation $circulation,
        User $actor,
        string $remarks,
        Request $request,
    ): DpcCirculation {
        return DB::transaction(function () use ($circulation, $actor, $remarks, $request): DpcCirculation {
            $locked = $this->lock($circulation);
            $this->assertAllottee($locked, $actor);

            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_circulation', 'return_to_department', $from, $actor);

            $locked->forceFill([
                'status' => CirculationStatus::Returned->value,
                'returned_at' => now(),
                'return_remarks' => $remarks,
            ])->save();

            $this->record($locked, 'return_to_department', $from, $def, $actor, $request, $remarks);

            return $locked->fresh();
        });
    }

    // ------------------------------------------------------------- internals

    /**
     * Release the holds the Commission consented to, and only those.
     *
     * Written **inside the signature transaction, after the signature
     * succeeds**. A hold released by a signature that failed would be a
     * promotion nobody authorised.
     *
     * Each release gets its own timeline event rather than being folded into
     * `sign`: a hold is the reason a promotion did not take effect, its
     * release is the reason one did, and each must be answerable on its own.
     *
     * @return list<string> the officers whose promotions now proceed
     */
    private function releaseConsentedHolds(DpcCirculation $circulation, User $actor): array
    {
        $released = [];

        foreach ($circulation->items()->orderBy('sn')->get() as $item) {
            if ($item->decision !== CirculationDecision::Consented) {
                continue;
            }

            $hold = DpcProceedingHold::query()->find($item->dpc_proceeding_hold_id);

            if ($hold === null || $hold->released_at !== null) {
                continue;
            }

            $releasedAt = now();

            // The one write M9 makes into a frozen milestone's table. M7's
            // migration declares this column as M9's seam, and the model's
            // own guard permits `released_at` and nothing else — so this is
            // the write it was left open for (AQ-21).
            $hold->update(['released_at' => $releasedAt]);
            $item->update(['hold_released_at' => $releasedAt]);

            $this->timeline->record(
                'dpc_circulation.hold_resolved',
                $circulation->originProposal,
                'Promotion released: '.$item->officer_name,
                array_merge(
                    [
                        'circulation_uuid' => $circulation->uuid,
                        'circulation_code' => $circulation->circulation_code,
                        'released_at' => $releasedAt->toIso8601String(),
                    ],
                    $item->fresh()->toTimelineProperties(),
                ),
                $actor,
            );

            $released[] = $item->officer_name;
        }

        return $released;
    }

    /**
     * **AQ-20, reusing AQ-6 verbatim.** The origin DPC's allottee — the person
     * recorded as having conducted the sitting that reserved the promotion —
     * is the person who releases it. Holding the `member` role is not enough,
     * and the Chairman is not exempt: exactly one actor stays accountable, and
     * that is the first question asked when a promotion is contested.
     */
    private function assertAllottee(DpcCirculation $circulation, User $actor): void
    {
        $conductedBy = $circulation->originProceeding?->conducted_by;

        if ($conductedBy === null || $conductedBy !== $actor->id) {
            throw new WorkflowException(
                'Only the Member who conducted the original DPC may act on this circulation.'
            );
        }
    }

    private function assertWithCommission(DpcCirculation $circulation): void
    {
        if (! $circulation->status->isWithCommission()) {
            throw new WorkflowException(
                'This circulation is not currently with the Commission.'
            );
        }
    }

    private function lock(DpcCirculation $circulation): DpcCirculation
    {
        return DpcCirculation::query()
            ->whereKey($circulation->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array{from:list<string>,to:string,role:string,signature:bool,notify:list<string>}  $def
     */
    private function record(
        DpcCirculation $circulation,
        string $event,
        string $from,
        array $def,
        User $actor,
        Request $request,
        ?string $remarks = null,
        ?int $signatureId = null,
    ): void {
        $this->engine->record(
            $circulation,
            'dpc_circulation',
            $event,
            $from,
            $def,
            $actor,
            $request,
            $remarks,
            $signatureId,
            $circulation->department_id,
            $circulation->toTimelineProperties(),
        );
    }

    private function decisionSummary(DpcCirculation $circulation): string
    {
        $consented = $circulation->items()->where('decision', CirculationDecision::Consented->value)->count();
        $returned = $circulation->items()->where('decision', CirculationDecision::Returned->value)->count();

        return "Consented: {$consented}; returned for further evidence: {$returned}.";
    }

    /**
     * What the Commission's signature covers.
     *
     * Plain text, as the department's approval is. **The prescribed form —
     * the rendered circulation note — is Phase 3**, and producing a half-built
     * document here to sign would be exactly the placeholder this project
     * refuses to declare.
     */
    private function renderForSignature(DpcCirculation $circulation): string
    {
        $lines = [
            'COMMISSION CONSENT — परिभ्रमण टीप',
            'Circulation: '.($circulation->circulation_code ?? '—'),
            'Origin DPC: '.($circulation->origin_dpc_code ?? '—'),
            'Decisions:',
        ];

        foreach ($circulation->items()->orderBy('sn')->get() as $item) {
            $lines[] = sprintf(
                '  %d. %s — %s',
                $item->sn,
                $item->officer_name,
                $item->decision?->label() ?? 'undecided',
            );
        }

        return implode("\n", $lines)."\n";
    }
}
