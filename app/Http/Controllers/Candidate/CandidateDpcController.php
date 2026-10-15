<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingDocument;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\UserManagement\Services\PromotionCandidateProvisioner;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Promotion Candidate's own view of their DPC (change item 17).
 *
 * **What the register permits, in its own words:** *"The candidate may only view
 * Own DPC, Own Status, Timeline, Notifications, Final Result (when published)."*
 * And what it forbids: *"Other Candidates, Committee, Evaluation, Internal
 * Remarks, Other Officers' Marks."*
 *
 * **So this controller is defined by its omissions, and they are deliberate.**
 * There is no route to a Seniority List, a Committee, an Evaluation Chart, a
 * calculation run, or another officer's anything — not "there is one but it 403s".
 * A screen that exists and is guarded is a screen whose guard can be got wrong;
 * a screen that does not exist cannot leak. Notifications and the timeline come
 * from the same shared screens every role uses, which already scope themselves to
 * the signed-in user.
 *
 * **Scoping is by employee number, once, here.** {@see ownProposals()} is the only
 * query that decides which DPCs are this candidate's, and every action re-derives
 * from it rather than trusting a route parameter — so a candidate who types
 * another proposal's UUID gets a 404 rather than somebody else's promotion.
 */
class CandidateDpcController extends Controller
{
    public function index(Request $request): View
    {
        return view('candidate.index', [
            'proposals' => $this->ownProposals($request),
        ]);
    }

    public function show(Request $request, string $dpcProposal): View
    {
        $proposal = $this->ownProposals($request)->firstWhere('uuid', $dpcProposal);

        if ($proposal === null) {
            // A 404 rather than a 403: telling a candidate that a proposal exists
            // but is not theirs discloses that it exists.
            throw new NotFoundHttpException;
        }

        $proceeding = DpcProceeding::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->latest('id')
            ->first();

        return view('candidate.show', [
            'proposal' => $proposal,

            /*
             | The candidate's own entry on the Seniority List — their name,
             | seniority number and the two posts.
             |
             | Deliberately not the list: the register forbids seeing other
             | candidates, and "the list filtered to one row" is the same query with
             | one more chance to get the filter wrong.
             */
            'entry' => PromotionCandidateProvisioner::entriesFor($request->user())
                ->where('dpc_proposal_id', $proposal->id)
                ->first(),

            'timeline' => $this->timelineFor($proposal),

            /*
             | The final result, and only once it is published.
             |
             | `published` is the gate the register names, and it is the Commission's
             | own act — the same one that releases the signed documents. Before it,
             | a candidate sees the DPC's *status* and nothing about the outcome,
             | because until publication there is no outcome to state.
             |
             | What is shown is whether the candidate was promoted, and never a
             | mark, a score, or another officer's row. The Evaluation Chart is
             | forbidden outright, and a candidate's own marks are part of it.
             */
            'result' => $this->publishedResultFor($proceeding, $request),
        ]);
    }

    /**
     * The DPCs this candidate is named on.
     *
     * Only proposals that have actually left the department: a draft is the
     * department's working paper, and an officer learning they are on a Seniority
     * List before it is filed would be learning the department's intentions rather
     * than their own status.
     *
     * @return Collection<int, DpcProposal>
     */
    private function ownProposals(Request $request): Collection
    {
        $ids = PromotionCandidateProvisioner::entriesFor($request->user())
            ->distinct()
            ->pluck('dpc_proposal_id');

        return DpcProposal::query()
            ->with(['promotionFromPost', 'promotionToPost', 'department'])
            ->whereIn('id', $ids)
            ->whereNotNull('forwarded_at')
            ->orderByDesc('dpc_year')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The DPC's public progress, as timeline entries.
     *
     * **Remarks are stripped, and that is the register's "Internal Remarks".** A
     * return remark is the Administrative Head telling the Authorized Officer what
     * to correct; it is internal to the department and routinely names other
     * officers' data. The candidate sees *that* the proposal was returned and when,
     * which is their status, and not what was said about it.
     *
     * @return Collection<int, ActivityTimeline>
     */
    private function timelineFor(DpcProposal $proposal): Collection
    {
        return ActivityTimeline::query()
            ->where('subject_type', $proposal->getMorphClass())
            ->where('subject_id', $proposal->id)
            ->whereIn('event', [
                'dpc_proposal.submit',
                'dpc_proposal.approve',
                'dpc_proposal.return',
                'dpc_cgpsc.allot',
                'dpc_cgpsc.accept',
                'dpc_cgpsc.schedule',
                'dpc_proceeding.open',
                'dpc_proceeding.approve_result',
            ])
            ->orderBy('created_at')
            ->get(['id', 'event', 'created_at']);
    }

    /**
     * Whether this candidate was promoted, once the result is published.
     *
     * Returns null while nothing is published, which the view renders as "the
     * result has not been declared" rather than as an absence of data.
     *
     * @return array{promoted: bool, declared_at: Carbon}|null
     */
    private function publishedResultFor(?DpcProceeding $proceeding, Request $request): ?array
    {
        if ($proceeding === null) {
            return null;
        }

        /*
         | Publication is `published_at` being set, not a status.
         |
         | `DocumentStatus` covers how *generation* went — pending, generated,
         | failed — and a generated document is not yet a published one: the
         | Commission releases it as a separate act. Reading a status here would
         | have shown a candidate their result the moment the document existed.
         */
        $published = DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->whereNotNull('published_at')
            ->orderBy('published_at')
            ->first();

        if ($published === null || $proceeding->final_calculation_run_id === null) {
            return null;
        }

        /*
         | The candidate's own row in the approved calculation, read by employee
         | number — the same identifier the whole feature keys on.
         |
         | Only `list_type` is read, and only to decide *promoted or not*. The row
         | also holds the score breakdown, the rank and the remark; none of them is
         | touched here, because the register forbids the Evaluation and a
         | candidate's own marks are part of it. `main` is the promotion list;
         | `waiting` and `none` are not promotions.
         */
        $result = DpcCalculationResult::query()
            ->where('dpc_calculation_run_id', $proceeding->final_calculation_run_id)
            ->where('employee_number', $request->user()->employee_number)
            ->first(['list_type']);

        if ($result === null) {
            return null;
        }

        return [
            'promoted' => $result->list_type === ListType::Main,
            'declared_at' => $published->published_at,
        ];
    }
}
