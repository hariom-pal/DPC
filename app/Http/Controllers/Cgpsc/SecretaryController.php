<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcProposalRevision;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Secretary, CGPSC (business decision, 2026-11-14).
 *
 * Every department's DPC the Administrative Head approved waits here. The Secretary
 * reads it on the AH's own review screens, then approves it with an eSign — it is
 * forwarded to the Chairman — or returns it to the Authorized Officer with a reason
 * and a supporting document, and it comes back through the AH and the Secretary.
 */
class SecretaryController extends Controller
{
    public function __construct(private readonly DpcProposalService $service) {}

    public function index(Request $request): View
    {
        $pending = DpcProposal::query()
            ->where('status', DpcProposalStatus::PendingSecretary->value)
            ->with('department', 'promotionFromPost', 'promotionToPost', 'approver')
            ->orderBy('approved_at')
            ->get();

        $decided = DpcProposal::query()
            ->whereNotNull('secretary_approved_at')
            ->with('department', 'promotionFromPost', 'promotionToPost', 'secretaryApprover')
            ->latest('secretary_approved_at')
            ->limit(50)
            ->get();

        $returned = DpcProposalRevision::query()
            ->where('returned_stage', 'secretary')
            ->with('proposal.department', 'proposal.promotionToPost', 'returner')
            ->latest('returned_at')
            ->limit(50)
            ->get();

        return view('secretary.index', ['pending' => $pending, 'decided' => $decided, 'returned' => $returned]);
    }

    /** Returned to the AO with the reason and a supporting document, both required. */
    public function return(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $this->authorize('secretaryReturn', $dpcProposal);

        $data = $request->validate([
            'return_remarks' => ['required', 'string', 'max:1000'],
            'return_document' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
        ], [
            'return_remarks.required' => (string) __('dpc.secretary.errors.remarks_required'),
            'return_document.required' => (string) __('dpc.secretary.errors.document_required'),
            'return_document.mimes' => (string) __('dpc.employee_details.documents.pdf_only'),
            'return_document.max' => (string) __('dpc.employee_details.documents.too_large', ['kb' => (int) config('dpc.documents.max_kb', 10240)]),
        ]);

        try {
            $this->service->secretaryReturn($dpcProposal, $request->user(), trim((string) $data['return_remarks']), $request->file('return_document'), $request);
        } catch (WorkflowException $e) {
            return back()->withErrors(['workflow' => $e->getMessage()]);
        }

        return redirect()->route('secretary.index')->with('status', __('dpc.secretary.returned'));
    }

    /** The Secretary's supporting document, to whoever may view the DPC. */
    public function returnDocument(DpcProposal $dpcProposal, DpcProposalRevision $revision): StreamedResponse
    {
        $this->authorize('view', $dpcProposal);
        abort_unless($revision->dpc_proposal_id === $dpcProposal->id && $revision->return_document_path !== null, 404);

        $disk = Storage::disk((string) config('dpc.documents.disk', 'local'));
        abort_unless($disk->exists($revision->return_document_path), 404);

        return $disk->response($revision->return_document_path, $revision->return_document_name ?? 'return-document.pdf', [
            'Content-Type' => 'application/pdf',
        ], 'inline');
    }
}
