<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The CGPSC Seniority List links (2026-11-22): CGPSC publishes each DPC's Seniority
 * List on its website, and the Administrator records that link here, one per DPC.
 * The candidate sees it above the list uploaded to the DPC, so the two can be
 * matched. A change is on the DPC's timeline.
 */
class SeniorityListLinkController extends Controller
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $missingOnly = $request->boolean('missing');

        $proposals = DpcProposal::query()
            ->with(['department', 'promotionFromPost', 'promotionToPost'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('dpc_code', 'like', $like)
                        ->orWhereHas('department', fn (Builder $d) => $d->where('name', 'like', $like))
                        ->orWhereHas('promotionToPost', fn (Builder $p) => $p->where('name', 'like', $like))
                        ->orWhereHas('promotionFromPost', fn (Builder $p) => $p->where('name', 'like', $like));
                });
            })
            ->when($missingOnly, fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('cgpsc_seniority_list_url')->orWhere('cgpsc_seniority_list_url', '')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.seniority-links.index', [
            'proposals' => $proposals,
            'search' => $search,
            'missingOnly' => $missingOnly,
            'pending' => DpcProposal::query()->where(fn (Builder $q) => $q->whereNull('cgpsc_seniority_list_url')->orWhere('cgpsc_seniority_list_url', ''))->count(),
        ]);
    }

    public function update(Request $request, DpcProposal $dpcProposal): RedirectResponse
    {
        $validated = $request->validateWithBag('link'.$dpcProposal->id, [
            'cgpsc_seniority_list_url' => ['nullable', 'string', 'max:500', 'url:https'],
        ]);

        $before = $dpcProposal->cgpsc_seniority_list_url;
        $after = ($validated['cgpsc_seniority_list_url'] ?? '') === '' ? null : (string) $validated['cgpsc_seniority_list_url'];

        if ($before !== $after) {
            $dpcProposal->forceFill(['cgpsc_seniority_list_url' => $after])->save();
            $this->timeline->record(
                'dpc_proposal.cgpsc_seniority_link_updated',
                $dpcProposal,
                $after === null ? (string) __('admin.seniority_links.timeline_removed') : (string) __('admin.seniority_links.timeline_set', ['url' => $after]),
                ['before' => $before, 'after' => $after],
            );
        }

        return back()->with('status', __('admin.seniority_links.saved', ['dpc' => $dpcProposal->dpc_code ?? $dpcProposal->id]));
    }
}
