<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Committee\Services\CommitteeDesignationLevel;
use App\Domain\Committee\Services\CommitteeMemberAccounts;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Requests\ReplaceCommitteeMemberRequest;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Master\Models\Designation;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The two things that happen to a committee **after** the Authorized Officer has
 * composed it (change register of 2026-08-26, item 9 as clarified, and item 9e).
 *
 * These are deliberately not on `DpcProposalController`. Everything there is the
 * Authorized Officer filling in a section of their own proposal while it is still
 * editable; both of these are somebody else acting on a proposal that may already
 * have been submitted, and the authorisation is correspondingly different:
 *
 * | Operation | Actor | Route guard |
 * |---|---|---|
 * | {@see designateChairman()} | CGPSC Chairman | `role:chairman` |
 * | {@see replace()} | CGPSC Administrator | `role:admin` |
 *
 * Neither consults `DpcProposalPolicy::update()`, and neither should: that policy
 * asks whether *this department's officer* may still edit *their* draft, which is
 * the wrong question for both actors and would refuse both of them the moment the
 * proposal was submitted — the point at which they most need to act.
 */
class DpcCommitteeMemberController extends Controller
{
    public function __construct(private readonly DpcCommitteeService $committee) {}

    /**
     * The CGPSC Chairman names who conducts this DPC.
     *
     * The candidate set is this committee and nothing wider, which the service
     * enforces; the Chairman's own seat is in it, so taking the conduct themselves
     * is the same operation rather than a special case.
     */
    public function designateChairman(Request $request, DpcProposal $dpcProposal, DpcCommitteeMember $member): RedirectResponse
    {
        try {
            $this->committee->designateChairman($dpcProposal, $member, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return back()->with('status', __('dpc.committee.chairman_designated', ['name' => $member->name]));
    }

    /**
     * The substitution form for one serving member.
     */
    public function replaceForm(DpcProposal $dpcProposal, DpcCommitteeMember $member): View
    {
        abort_unless($member->dpc_proposal_id === $dpcProposal->id, 404);
        abort_unless($member->isServing(), 404);

        return view('dpc-proposals.committee-replace', [
            'proposal' => $dpcProposal,
            'member' => $member,

            // The successor's designation is chosen from the master, exactly as on
            // the composition form (DPC-MODULE-FIX-001, second pass).
            // At the feeder post's level, above it, or one level below (MoM of 12 November 2026).
            'designations' => app(CommitteeDesignationLevel::class)->selectable($dpcProposal, Designation::query()
                ->where('status', MasterStatus::Active)
                ->orderBy('name')
                ->get()),
        ]);
    }

    /**
     * Substitute a committee member, at any stage of the DPC (item 9e).
     */
    public function replace(
        ReplaceCommitteeMemberRequest $request,
        DpcProposal $dpcProposal,
        DpcCommitteeMember $member,
    ): RedirectResponse {
        $data = $request->validated();

        try {
            $successor = $this->committee->replace(
                $dpcProposal,
                $member,
                $data,
                $request->user(),
                (string) $data['reason'],
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()
            ->route('dpc-proposals.committee', $dpcProposal)
            ->with('status', __('dpc.committee.member_replaced', [
                'outgoing' => $member->name,
                'incoming' => $successor->name,
            ]));
    }

    /**
     * Every serving DPC committee member, with the state of the login (2026-11-18):
     * the Administrator's list to create or link a login and start a reset from.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('admin.committee-members.index', [
            'search' => $search,
            'members' => DpcCommitteeMember::query()
                ->serving()
                ->with(['user.activeAssignment.department', 'proposal'])
                ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('mobile', 'like', '%'.$search.'%')
                    ->orWhereHas('proposal', fn ($p) => $p->where('dpc_code', 'like', '%'.$search.'%'))))
                ->orderByDesc('dpc_proposal_id')
                ->orderBy('sort_order')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    /**
     * Create (or link) the login of a seat recorded without one (2026-11-18). The
     * Administrator's, under `role:admin`.
     */
    public function createLogin(Request $request, DpcProposal $dpcProposal, DpcCommitteeMember $member): RedirectResponse
    {
        abort_unless($member->dpc_proposal_id === $dpcProposal->id, 404);

        try {
            $user = app(CommitteeMemberAccounts::class)->createLogin($member, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['committee_account' => $e->getMessage()]);
        }

        return back()->with('status', __('dpc.committee.account.created', ['name' => $user->name]));
    }

    /**
     * Start the member's password reset (2026-11-18): codes to the member's own
     * registered e-mail and mobile; no password is issued or shown.
     */
    public function resetPassword(Request $request, DpcProposal $dpcProposal, DpcCommitteeMember $member): RedirectResponse
    {
        abort_unless($member->dpc_proposal_id === $dpcProposal->id && $member->user !== null, 404);

        try {
            app(CommitteeMemberAccounts::class)->initiateReset($member->user, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['committee_account' => $e->getMessage()]);
        }

        return back()->with('status', __('dpc.committee.account.reset_started', ['name' => $member->user->name]));
    }
}
