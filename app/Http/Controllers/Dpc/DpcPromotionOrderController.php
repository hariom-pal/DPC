<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PromotionOrder\Models\DpcPromotionOrder;
use App\Domain\PromotionOrder\Requests\IssuePromotionOrderRequest;
use App\Domain\PromotionOrder\Services\PromotionOrderService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Coder Instructions rule 4.3: the department's promotion orders for a DPC — issued
 * by the Administrative Head on the CGPSC's approved result, read by the department,
 * and opened by the officers and members through the signed link they are sent.
 */
class DpcPromotionOrderController extends Controller
{
    public function __construct(private readonly PromotionOrderService $orders) {}

    public function index(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('view', $dpcProposal);

        $proceeding = $this->orders->approvedProceeding($dpcProposal);
        $user = $request->user();

        return view('dpc-proposals.promotion-orders', [
            'proposal' => $dpcProposal,
            'proceeding' => $proceeding,
            'promoted' => $proceeding === null ? collect() : $this->orders->promoted($proceeding),
            'orders' => DpcPromotionOrder::query()->where('dpc_proposal_id', $dpcProposal->id)->with('issuer')->orderByDesc('id')->get(),
            'canIssue' => $proceeding !== null && $user !== null && $user->can('issueOrder', $dpcProposal),
        ]);
    }

    public function store(IssuePromotionOrderRequest $request, DpcProposal $dpcProposal): RedirectResponse
    {
        try {
            $this->orders->issue($dpcProposal, $request->user(), (string) $request->validated('order_number'), (string) $request->validated('order_date'));
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()->route('dpc-proposals.promotion-orders.index', $dpcProposal)
            ->with('status', __('dpc.promotion_orders.issued'));
    }

    public function download(DpcProposal $dpcProposal, DpcPromotionOrder $order): Response
    {
        $this->authorize('view', $dpcProposal);
        abort_unless($order->dpc_proposal_id === $dpcProposal->id && $order->content_hash !== null, 404);

        return $this->pdf($order);
    }

    /**
     * The link sent by SMS and e-mail: signed, so a member without an account can open it.
     */
    public function public(DpcPromotionOrder $order): Response
    {
        abort_unless($order->content_hash !== null, 404);

        return $this->pdf($order);
    }

    private function pdf(DpcPromotionOrder $order): Response
    {
        return response($this->orders->contents($order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="promotion-order-'.$order->version.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
