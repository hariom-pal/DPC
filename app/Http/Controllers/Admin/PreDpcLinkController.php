<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\PreDpcLinkReconciler;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Record the Pre-DPC that sanctioned a DPC raised before change item 11.
 *
 * A read-and-decide screen, not a fixer. See {@see PreDpcLinkReconciler} for why
 * the link cannot be derived — and therefore why this screen exists at all.
 */
class PreDpcLinkController extends Controller
{
    public function __construct(private readonly PreDpcLinkReconciler $reconciler) {}

    public function index(): View
    {
        $unlinked = $this->reconciler->unlinked();

        return view('admin.pre-dpc-links.index', [
            'proposals' => $unlinked,

            // Candidates per proposal, keyed by id. Loaded here rather than in the
            // view so the query count is visible in one place.
            'candidates' => $unlinked
                ->mapWithKeys(fn (DpcProposal $p): array => [$p->id => $this->reconciler->candidatesFor($p)])
                ->all(),
        ]);
    }

    public function store(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $validated = $request->validate([
            'pre_dpc_proposal_id' => ['required', 'integer', 'exists:pre_dpc_proposals,id'],
        ]);

        $preDpc = PreDpcProposal::query()->findOrFail($validated['pre_dpc_proposal_id']);

        try {
            $this->reconciler->link($dpcProposal, $preDpc, (string) $request->user()?->name);
        } catch (RuntimeException $e) {
            // The service's refusals are business statements, so they are shown as
            // validation rather than as a 500.
            return back()->withErrors(['pre_dpc_proposal_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.pre-dpc-links.index')
            ->with('status', __('admin.pre_dpc_links.index.recorded'));
    }
}
