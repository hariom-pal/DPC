<?php

declare(strict_types=1);

namespace App\Domain\PromotionOrder\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Proceeding\Models\DpcSealedCoverOpening;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\PromotionOrder\Events\PromotionOrderIssued;
use App\Domain\PromotionOrder\Models\DpcPromotionOrder;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\SignatureService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Coder Instructions rule 4.3 (business decisions, 2026-10-21): the promotion order.
 *
 * The Administrative Head issues it once the CGPSC has approved the result, giving
 * the order number and date; the order lists the approved result's Main List except
 * an officer under sealed cover (rule 2.2), is rendered as a PDF, signed with the
 * AH's digital signature, and its link is sent to the officers and the committee.
 * Seniority on the promoted post runs from the order date. An issued order is never
 * rewritten: issuing again supersedes it.
 */
final class PromotionOrderService
{
    public function __construct(
        private readonly SignatureService $signatures,
        private readonly ActivityTimelineService $timeline,
        private readonly ResultApprovalService $results,
    ) {}

    /** The CGPSC proceeding whose approved result an order is issued on, if any. */
    public function approvedProceeding(DpcProposal $proposal): ?DpcProceeding
    {
        return DpcProceeding::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('status', DpcProceedingStatus::ResultApproved->value)
            ->latest('id')
            ->first();
    }

    /**
     * The officers an order promotes: the approved result's Main List, in its order,
     * without an officer whose finding is in a sealed cover, nor one held (CIR)
     * whose hold no signed circulation has released (2026-11-05): the promotion
     * action is reserved until then, and issuing the order again after the
     * circulation promotes them.
     *
     * @return Collection<int, DpcCalculationResult>
     */
    public function promoted(DpcProceeding $proceeding): Collection
    {
        $run = $proceeding->finalCalculationRun;

        if ($run === null) {
            return new Collection;
        }

        return $this->results->lists($run)['main']
            ->reject(fn (DpcCalculationResult $result): bool => $result->status() === RemarkCode::SealedCover || $this->isHeld($proceeding, $result))
            ->values();
    }

    /**
     * Whether an officer's promotion is still held (2026-11-05): an unreleased hold,
     * or a CIR result whose holds have not all been released by a signed
     * circulation. The seat stays reserved meanwhile; a CIR with no hold to release
     * (a missing Loyalty Certificate) stays held.
     */
    public function isHeld(DpcProceeding $proceeding, DpcCalculationResult $result): bool
    {
        $holds = DpcProceedingHold::query()->where('dpc_proceeding_id', $proceeding->id)->where('sn', (int) $result->sn)->get();

        if ($holds->contains(fn (DpcProceedingHold $hold): bool => $hold->released_at === null)) {
            return true;
        }

        return $result->status() === RemarkCode::Circulation && $holds->isEmpty();
    }

    public function current(DpcProposal $proposal): ?DpcPromotionOrder
    {
        return DpcPromotionOrder::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('kind', DpcPromotionOrder::KIND_REGULAR)
            ->where('status', DpcPromotionOrder::STATUS_ISSUED)
            ->latest('id')
            ->first();
    }

    public function issue(DpcProposal $proposal, User $actor, string $orderNumber, string $orderDate): DpcPromotionOrder
    {
        $proceeding = $this->approvedProceeding($proposal);

        if ($proceeding === null) {
            throw new WorkflowException(__('dpc.promotion_orders.no_approved_result'));
        }

        $promoted = $this->promoted($proceeding);

        if ($promoted->isEmpty()) {
            throw new WorkflowException(__('dpc.promotion_orders.nobody_promoted'));
        }

        $order = DB::transaction(function () use ($proposal, $proceeding, $promoted, $actor, $orderNumber, $orderDate): DpcPromotionOrder {
            $previous = $this->current($proposal);
            $version = (int) DpcPromotionOrder::query()->where('dpc_proposal_id', $proposal->id)->max('version') + 1;

            $order = DpcPromotionOrder::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'dpc_proceeding_id' => $proceeding->id,
                'kind' => DpcPromotionOrder::KIND_REGULAR,
                'order_number' => trim($orderNumber),
                'order_date' => Carbon::parse($orderDate)->toDateString(),
                'version' => $version,
                'supersedes_id' => $previous?->id,
                'status' => DpcPromotionOrder::STATUS_ISSUED,
                'officers' => $promoted->map(fn (DpcCalculationResult $result): array => [
                    'sn' => (int) $result->sn,
                    'name' => (string) $result->officer_name,
                    'employee_number' => $result->employee_number,
                    'category' => $result->reservation_category_code,
                    'list_position' => $result->list_position,
                ])->all(),
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            $contents = $this->render($order, $previous, $actor);
            $disk = (string) config('dpc.documents.disk', 'local');
            $path = sprintf('private/promotion-orders/%s/%s-v%d.pdf', $proposal->uuid, $order->uuid, $version);
            Storage::disk($disk)->put($path, $contents);

            $signature = $this->signatures->sign($actor, $order, $contents, sprintf('promotion-order-v%d.pdf', $version), [
                'proposal_uuid' => (string) $proposal->uuid,
                'proceeding_uuid' => $proceeding->uuid,
                'order_number' => $order->order_number,
            ]);

            $order->forceFill([
                'disk' => $disk,
                'path' => $path,
                'content_hash' => hash('sha256', $contents),
                'signature_id' => $signature->id,
            ])->save();

            $previous?->forceFill(['status' => DpcPromotionOrder::STATUS_SUPERSEDED, 'superseded_at' => now()])->save();

            $this->timeline->record('dpc_proposal.promotion_order_issued', $proposal, __('dpc.promotion_orders.timeline', [
                'number' => $order->order_number,
                'date' => $order->order_date->format('d-m-Y'),
                'count' => count($order->officers),
            ]), [
                'order_uuid' => $order->uuid,
                'version' => $version,
                'supersedes' => $previous?->uuid,
                'content_hash' => $order->content_hash,
            ], $actor);

            return $order;
        });

        PromotionOrderIssued::dispatch($order);

        return $order;
    }

    public function contents(DpcPromotionOrder $order): string
    {
        return (string) Storage::disk((string) $order->disk)->get((string) $order->path);
    }

    /**
     * The order a sealed cover's opening leads to (2026-11-05): the cleared officer,
     * effective on the opening date, or the candidate a rejection before 31 December
     * offers the seat to. Signed by the AH as every order is; the DPC's own orders are
     * untouched. Issued again, it supersedes its own earlier version only.
     */
    public function issueFollowUp(DpcSealedCoverOpening $opening, User $actor, string $orderNumber, string $orderDate): DpcPromotionOrder
    {
        if (! $opening->promotes()) {
            throw new WorkflowException((string) __('dpc.sealed_cover.errors.nothing_to_order'));
        }

        $proceeding = $opening->proceeding;
        $run = $proceeding?->finalCalculationRun;
        $sn = $opening->isCleared() ? $opening->sn : (int) $opening->replacement_sn;
        $result = $run === null ? null : DpcCalculationResult::query()->where('dpc_calculation_run_id', $run->id)->where('sn', $sn)->first();

        if ($proceeding === null || $result === null) {
            throw new WorkflowException((string) __('dpc.promotion_orders.no_approved_result'));
        }

        // Never two promotions against one seat: the officer is on no other order.
        $alreadyOrdered = DpcPromotionOrder::query()->where('dpc_proposal_id', $opening->dpc_proposal_id)
            ->where('status', DpcPromotionOrder::STATUS_ISSUED)
            ->where(fn ($query) => $query->whereNull('dpc_sealed_cover_opening_id')->orWhere('dpc_sealed_cover_opening_id', '!=', $opening->id))
            ->get()
            ->contains(fn (DpcPromotionOrder $order): bool => in_array($sn, array_map(fn (array $officer): int => (int) $officer['sn'], (array) $order->officers), true));

        if ($alreadyOrdered) {
            throw new WorkflowException((string) __('dpc.sealed_cover.errors.already_promoted'));
        }

        $proposal = $opening->proposal;

        $order = DB::transaction(function () use ($opening, $proposal, $proceeding, $result, $actor, $orderNumber, $orderDate): DpcPromotionOrder {
            $previous = DpcPromotionOrder::query()->where('dpc_sealed_cover_opening_id', $opening->id)
                ->where('status', DpcPromotionOrder::STATUS_ISSUED)->latest('id')->first();
            $version = (int) DpcPromotionOrder::query()->where('dpc_proposal_id', $proposal->id)->max('version') + 1;

            $order = DpcPromotionOrder::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'dpc_proceeding_id' => $proceeding->id,
                'kind' => DpcPromotionOrder::KIND_SEALED_COVER,
                'dpc_sealed_cover_opening_id' => $opening->id,
                'order_number' => trim($orderNumber),
                'order_date' => Carbon::parse($orderDate)->toDateString(),
                // The cleared officer from the day the cover was opened, never the DPC's
                // date; the replacement from the day this order is issued (2026-11-05).
                'effective_date' => $opening->isCleared() ? $opening->opened_on->toDateString() : Carbon::parse($orderDate)->toDateString(),
                'version' => $version,
                'supersedes_id' => $previous?->id,
                'status' => DpcPromotionOrder::STATUS_ISSUED,
                'officers' => [[
                    'sn' => (int) $result->sn,
                    'name' => (string) $result->officer_name,
                    'employee_number' => $result->employee_number,
                    'category' => $result->reservation_category_code,
                    'list_position' => $result->list_position,
                ]],
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            $contents = $this->render($order, $previous, $actor);
            $disk = (string) config('dpc.documents.disk', 'local');
            $path = sprintf('private/promotion-orders/%s/%s-v%d.pdf', $proposal->uuid, $order->uuid, $version);
            Storage::disk($disk)->put($path, $contents);

            $signature = $this->signatures->sign($actor, $order, $contents, sprintf('promotion-order-v%d.pdf', $version), [
                'proposal_uuid' => (string) $proposal->uuid,
                'proceeding_uuid' => $proceeding->uuid,
                'order_number' => $order->order_number,
                'sealed_cover_opening_uuid' => $opening->uuid,
            ]);

            $order->forceFill([
                'disk' => $disk,
                'path' => $path,
                'content_hash' => hash('sha256', $contents),
                'signature_id' => $signature->id,
            ])->save();

            $previous?->forceFill(['status' => DpcPromotionOrder::STATUS_SUPERSEDED, 'superseded_at' => now()])->save();

            $this->timeline->record('dpc_proposal.promotion_order_issued', $proposal, __('dpc.promotion_orders.timeline', [
                'number' => $order->order_number,
                'date' => $order->order_date->format('d-m-Y'),
                'count' => 1,
            ]), [
                'order_uuid' => $order->uuid,
                'version' => $version,
                'kind' => $order->kind,
                'sealed_cover_opening_uuid' => $opening->uuid,
                'content_hash' => $order->content_hash,
            ], $actor);

            return $order;
        });

        PromotionOrderIssued::dispatch($order);

        return $order;
    }

    private function render(DpcPromotionOrder $order, ?DpcPromotionOrder $previous, User $actor): string
    {
        $proposal = $order->proposal()->with(['department', 'promotionFromPost', 'promotionToPost', 'payScale'])->firstOrFail();

        $html = view('documents.promotion-order', [
            'order' => $order,
            'proposal' => $proposal,
            'proceeding' => $order->proceeding,
            'previous' => $previous,
            'issuer' => $actor,
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
