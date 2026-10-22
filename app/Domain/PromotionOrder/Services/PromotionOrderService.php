<?php

declare(strict_types=1);

namespace App\Domain\PromotionOrder\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
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
     * without an officer whose finding is in a sealed cover.
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
            ->reject(fn (DpcCalculationResult $result): bool => $result->status() === RemarkCode::SealedCover)
            ->values();
    }

    public function current(DpcProposal $proposal): ?DpcPromotionOrder
    {
        return DpcPromotionOrder::query()
            ->where('dpc_proposal_id', $proposal->id)
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
