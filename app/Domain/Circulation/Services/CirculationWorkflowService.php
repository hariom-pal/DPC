<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Shared\Workflow\WorkflowEngine;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The department's side of a circulation note (PPT S45) — M9 Phase 1.
 *
 * **No new workflow engine.** The `dpc_circulation` definition is
 * configuration (AD-11, AD-3), so the transitions below are declared in
 * `config/dpc.php` and enforced by the same engine M3 built and every
 * milestone since has reused.
 *
 * **Phase 1 declares only the department's transitions.** `consent`,
 * `return_to_department` and `sign` belong to the Commission and arrive with
 * Phase 2 — a transition nothing can perform reads as supported behaviour when
 * it is not.
 *
 * **The Administrative Head signs the approval (AQ-23).** Every
 * department-originated artefact in this system is signed by the AH before it
 * reaches CGPSC, and a confirmation that a reserved promotion should proceed is
 * at least as significant as the proposal that produced it.
 *
 * Nothing here reaches the calculation, eligibility, zone or proceeding
 * services (AD-23), and nothing here writes to `dpc_proposals` (AD-24).
 */
class CirculationWorkflowService
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly SignatureService $signatures,
        private readonly CirculationCodeService $codes,
    ) {}

    public function submit(DpcCirculation $circulation, User $actor, Request $request): DpcCirculation
    {
        return DB::transaction(function () use ($circulation, $actor, $request): DpcCirculation {
            $locked = $this->lock($circulation);

            if ($locked->items()->count() === 0) {
                throw new WorkflowException(
                    'This circulation covers no officers and cannot be submitted.'
                );
            }

            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_circulation', 'submit', $from, $actor);

            $locked->forceFill(['status' => CirculationStatus::Submitted->value])->save();

            $this->record($locked, 'submit', $from, $def, $actor, $request);

            return $locked->fresh();
        });
    }

    /**
     * The AH approves and signs; the note is forwarded to the Commission and
     * earns its `circulation_code`.
     *
     * The code is minted here rather than at raising for the same reason M4
     * mints a DPC ID at approval: an identifier that reaches the Commission
     * should exist only once the department has committed to the document.
     */
    public function approve(DpcCirculation $circulation, User $actor, Request $request): DpcCirculation
    {
        return DB::transaction(function () use ($circulation, $actor, $request): DpcCirculation {
            $locked = $this->lock($circulation);
            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_circulation', 'approve', $from, $actor);

            $signature = $this->signatures->sign(
                $actor,
                $locked,
                $this->renderForSignature($locked),
                "dpc-circulation-{$locked->id}.txt",
            );

            $locked->forceFill([
                'status' => CirculationStatus::Approved->value,
                'approved_by' => $actor->id,
                'approved_by_name' => $actor->name,
                'approved_at' => now(),
                'approval_signature_id' => $signature->id,
                'circulation_code' => $locked->circulation_code ?? $this->codes->next($locked),
            ])->save();

            $this->record($locked, 'approve', $from, $def, $actor, $request, null, $signature->id);

            return $locked->fresh();
        });
    }

    public function return(DpcCirculation $circulation, User $actor, string $remarks, Request $request): DpcCirculation
    {
        return DB::transaction(function () use ($circulation, $actor, $remarks, $request): DpcCirculation {
            $locked = $this->lock($circulation);
            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_circulation', 'return', $from, $actor);

            $locked->forceFill([
                'status' => CirculationStatus::Returned->value,
                'returned_at' => now(),
                'return_remarks' => $remarks,
            ])->save();

            $this->record($locked, 'return', $from, $def, $actor, $request, $remarks);

            return $locked->fresh();
        });
    }

    public function cancel(DpcCirculation $circulation, User $actor, Request $request): DpcCirculation
    {
        return DB::transaction(function () use ($circulation, $actor, $request): DpcCirculation {
            $locked = $this->lock($circulation);
            $from = $locked->status->value;
            $def = $this->engine->assertAllowed('dpc_circulation', 'cancel', $from, $actor);

            $locked->forceFill([
                'status' => CirculationStatus::Cancelled->value,
                'cancelled_at' => now(),
            ])->save();

            $this->record($locked, 'cancel', $from, $def, $actor, $request);

            return $locked->fresh();
        });
    }

    // ------------------------------------------------------------- internals

    /**
     * Re-fetch under a row lock before reading status — M4's discipline, which
     * M3 never got (TD-5). Two administrators acting at once must not both
     * pass the same state check.
     */
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

    /**
     * What the AH's signature covers.
     *
     * Plain text, like M4's proposal signature: the signed artefact is the
     * *statement of what was approved*, and the rendered note itself is Phase
     * 3's job. Producing a half-built document here to sign would be the
     * placeholder M7's Phase 3 refused to declare.
     */
    private function renderForSignature(DpcCirculation $circulation): string
    {
        $lines = [
            'CIRCULATION NOTE — परिभ्रमण टीप',
            'Origin DPC: '.($circulation->origin_dpc_code ?? '—'),
            'Circulation proposal date: '.$circulation->proposal_date->toDateString(),
            'Officers covered:',
        ];

        foreach ($circulation->items()->orderBy('sn')->get() as $item) {
            $lines[] = sprintf(
                '  %d. %s — %s (resolution received %s)',
                $item->sn,
                $item->officer_name,
                $item->reason_code->label(),
                $item->resolution_received_on->toDateString(),
            );
        }

        return implode("\n", $lines)."\n";
    }
}
