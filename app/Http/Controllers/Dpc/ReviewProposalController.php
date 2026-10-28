<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\Review\Requests\CreateReviewProposalRequest;
use App\Domain\Review\Services\ReviewDpcService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Review DPC — raising one, and reading a correction chain (PPT S35, S37) —
 * M8 Phase 1.
 *
 * The department is the only author of a Review (architect ruling AQ-10): a
 * Commission-initiated review is handled out of band, by directing the
 * department to raise it.
 */
class ReviewProposalController extends Controller
{
    public function __construct(
        private readonly ReviewDpcService $reviews,
        private readonly ProposalAllotmentService $allotments,
    ) {}

    /**
     * S37 — raise a Review against a concluded DPC.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', DpcProposal::class);

        return view('dpc.reviews.create', [
            // Raised by the AO the original is allotted to (2026-10-26).
            'originals' => $this->reviews->eligibleOriginals((int) $request->user()->department_id)
                ->filter(fn (DpcProposal $original): bool => $this->allotments->isAllotted($request->user(), $original, Role::AuthorizedOfficer))
                ->values(),
            'reasons' => ReviewReason::query()->orderBy('id')->get(),
        ]);
    }

    public function store(CreateReviewProposalRequest $request): RedirectResponse
    {
        $original = DpcProposal::query()
            ->where('uuid', (string) $request->input('original_dpc_proposal_uuid'))
            ->firstOrFail();

        $this->authorize('create', DpcProposal::class);
        abort_unless($this->allotments->isAllotted($request->user(), $original, Role::AuthorizedOfficer), 403);

        /** @var array<int, string> $details */
        $details = (array) $request->input('details', []);

        $reasons = collect((array) $request->input('reasons'))
            ->map(fn ($id): array => [
                'review_reason_id' => (int) $id,
                'detail' => $details[(int) $id] ?? null,
            ])
            ->values()
            ->all();

        try {
            $review = $this->reviews->create(
                $original,
                $request->user(),
                Carbon::parse((string) $request->input('proposal_date')),
                $reasons,
            );
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['review' => $e->getMessage()]);
        }

        return redirect()
            ->route('dpc-proposals.show', $review)
            ->with('status', 'The Review DPC has been raised. The original proposal is unchanged.');
    }

    /**
     * The complete correction chain (architect Directive 4).
     */
    public function chain(DpcProposal $dpcProposal): View
    {
        $this->authorize('view', $dpcProposal);

        $chainUuid = $this->reviews->chainUuidFor($dpcProposal);

        return view('dpc.reviews.chain', [
            'proposal' => $dpcProposal,
            'chainUuid' => $chainUuid,
            'chain' => $this->reviews->chain($dpcProposal),
            'entries' => $chainUuid === null ? collect() : $this->reviews->historyFromTimeline($chainUuid),
        ]);
    }
}
