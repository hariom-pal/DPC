<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dpc;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\CandidateVerification\Enums\CycleStatus;
use App\Domain\CandidateVerification\Enums\GrievanceDecision;
use App\Domain\CandidateVerification\Enums\GrievanceStatus;
use App\Domain\CandidateVerification\Enums\VerificationSection;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Models\GrievanceCorrection;
use App\Domain\CandidateVerification\Models\VerificationCycle;
use App\Domain\CandidateVerification\Models\VerificationNotification;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\CandidateVerification\Services\GrievanceReviewService;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\EsignGate;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\SignedActions\ApproveGrievanceCorrections;
use App\Http\SignedActions\NotUpholdGrievance;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The candidates' grievances on a DPC (2026-11-19): the AH decides each — without a
 * correction, or after opening that candidate's section for the AO — the AO sends a
 * correction back, and the Secretary reads the outcome. Readers are the proposal's
 * (DpcProposalPolicy::view); who may act is the service's, under its lock.
 */
class GrievanceController extends Controller
{
    public function __construct(private readonly GrievanceReviewService $reviews) {}

    /**
     * The AH's own entry point to the grievances (2026-11-22): every DPC allotted to
     * them whose candidates raised grievances, with what is still to decide — not only
     * the link inside each proposal's Candidate Verification section.
     */
    public function queue(Request $request, ProposalAllotmentService $allotments): View
    {
        $user = $request->user();
        $proposals = $allotments->scopeTo(DpcProposal::query(), $user)
            // Every DPC with a grievance submitted — while the verification is still open too (2026-11-23).
            ->whereIn('id', VerificationCycle::query()->whereIn('id', Grievance::query()->where('status', '!=', GrievanceStatus::Draft->value)->select('dpc_verification_cycle_id'))->select('dpc_proposal_id'))
            ->with('department', 'promotionFromPost', 'promotionToPost')
            ->get();

        $cycles = VerificationCycle::query()->whereIn('dpc_proposal_id', $proposals->modelKeys())->get()->keyBy('dpc_proposal_id');
        $counts = Grievance::query()
            ->whereIn('dpc_verification_cycle_id', $cycles->modelKeys())
            ->where('status', '!=', GrievanceStatus::Draft->value)
            ->selectRaw('dpc_verification_cycle_id, status, count(*) as n')
            ->groupBy('dpc_verification_cycle_id', 'status')
            ->get()
            ->groupBy('dpc_verification_cycle_id');

        $rows = $proposals->map(function (DpcProposal $proposal) use ($cycles, $counts): array {
            $cycle = $cycles->get($proposal->id);
            $byStatus = $counts->get($cycle?->id, collect())->mapWithKeys(fn ($r) => [$r->status instanceof GrievanceStatus ? $r->status->value : (string) $r->status => (int) $r->n]);

            return [
                'proposal' => $proposal,
                // Still open (2026-11-23): its grievances come in as each candidate submits.
                'open' => $cycle?->status === CycleStatus::Open,
                'candidates' => Grievance::query()->where('dpc_verification_cycle_id', $cycle?->id)->where('status', '!=', GrievanceStatus::Draft->value)->distinct()->count('dpc_verification_participant_id'),
                'to_decide' => ($byStatus[GrievanceStatus::Submitted->value] ?? 0) + ($byStatus[GrievanceStatus::ReturnedFromAo->value] ?? 0),
                'with_ao' => $byStatus[GrievanceStatus::SentToAo->value] ?? 0,
                'decided' => $byStatus[GrievanceStatus::Resolved->value] ?? 0,
            ];
        })
            // What still needs the AH first.
            ->sortByDesc(fn (array $row): int => $row['to_decide'] * 1000 + $row['with_ao'])
            ->values();

        return view('dpc-proposals.grievance-queue', ['rows' => $rows]);
    }

    public function index(Request $request, DpcProposal $dpcProposal): View
    {
        $this->authorize('view', $dpcProposal);
        $cycle = VerificationCycle::query()->where('dpc_proposal_id', $dpcProposal->id)->first();
        // Nothing of the grievances while the candidates' period runs (2026-11-20): every
        // candidate has until the deadline, and the AH sees them all together after it.
        // Open as well (2026-11-23): each grievance is the AH's as soon as it is submitted.
        abort_if($cycle === null, 404);

        $filters = $request->validate([
            'section' => ['nullable', Rule::enum(VerificationSection::class)],
            'status' => ['nullable', Rule::enum(GrievanceStatus::class)],
            'candidate' => ['nullable', 'string', 'max:120'],
        ]);

        $grievances = Grievance::query()
            ->where('dpc_verification_cycle_id', $cycle->id)
            ->where('status', '!=', GrievanceStatus::Draft->value)
            ->when($filters['section'] ?? null, fn ($q, $s) => $q->where('section', $s))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['candidate'] ?? null, fn ($q, $c) => $q->whereHas('participant.row', fn ($r) => $r->where('name', 'like', '%'.$c.'%')->orWhere('employee_number', $c)))
            ->with(['participant.row', 'corrections', 'resolver'])
            ->orderBy('dpc_verification_participant_id')->orderBy('section')->orderBy('id')
            ->get()
            // Candidate-wise (2026-11-22): each candidate's grievances together, in seniority order.
            ->sortBy(fn (Grievance $g): int => (int) $g->participant->row->sn)
            ->groupBy('dpc_verification_participant_id');

        return view('dpc-proposals.grievances', [
            'proposal' => $dpcProposal,
            'cycle' => $cycle,
            // The AH acts in the review, and while the verification is open (2026-11-23).
            'actionable' => $this->reviews->actionable($dpcProposal),
            // Who is looking (2026-11-24): the AH decides and approves, the AO corrects.
            'isAh' => app(ProposalAllotmentService::class)->isAllotted($request->user(), $dpcProposal, Role::AdministrativeHead),
            'isAo' => app(ProposalAllotmentService::class)->isAllotted($request->user(), $dpcProposal, Role::AuthorizedOfficer),
            'grievances' => $grievances,
            'filters' => $filters,
            'counts' => [
                'submitted' => $cycle->participants()->whereNotNull('submitted_at')->count(),
                'no_response' => $cycle->participants()->where('status', 'no_response')->count(),
                'grievances' => Grievance::query()->where('dpc_verification_cycle_id', $cycle->id)->where('status', '!=', GrievanceStatus::Draft->value)->count(),
            ],
            'notifications' => VerificationNotification::query()->where('dpc_verification_cycle_id', $cycle->id)
                ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'corrections' => GrievanceCorrection::query()->where('status', GrievanceCorrection::OPEN)
                ->whereHas('grievance', fn ($q) => $q->where('dpc_verification_cycle_id', $cycle->id))->with('grievance.participant.row')->get(),
        ]);
    }

    /**
     * The AH's decision on one grievance (2026-11-24). Upheld: the candidate is right —
     * their section opens for the AO with these instructions. Not upheld: the record
     * stands — closed with this reason, after the AH's eSign.
     */
    public function decide(Request $request, DpcProposal $dpcProposal, Grievance $grievance): RedirectResponse
    {
        $this->assertBelongs($dpcProposal, $grievance);
        $data = $request->validate([
            'decision' => ['required', Rule::enum(GrievanceDecision::class)],
            'text' => ['required', 'string', 'max:2000'],
        ]);
        $back = redirect()->to(route('dpc-proposals.grievances.index', $dpcProposal).'#grievance-'.$grievance->uuid);

        if (GrievanceDecision::from($data['decision']) === GrievanceDecision::NotUpheld) {
            // Checked before the eSign, so nobody signs a step that cannot run.
            if (! $this->isDpcAh($request, $dpcProposal)) {
                return $back->withErrors(['grievance' => __('candidate.grievance.errors.not_yours')]);
            }
            if (! $this->reviews->actionable($dpcProposal) || $grievance->status !== GrievanceStatus::Submitted) {
                return $back->withErrors(['grievance' => __('candidate.grievance.errors.not_decidable')]);
            }

            return app(EsignGate::class)->begin($request, $request->user(), NotUpholdGrievance::class, [
                'proposal' => $dpcProposal->uuid, 'grievance' => $grievance->uuid, 'reason' => trim($data['text']),
            ]);
        }

        try {
            $this->reviews->uphold($grievance, $data['text'], $request->user());
        } catch (WorkflowException $e) {
            return $back->withErrors(['grievance' => $e->getMessage()]);
        }

        return $back->with('status', __('candidate.grievance.review.upheld_done'));
    }

    /** Only this DPC's allotted AH begins an eSign on its grievances (2026-11-24). */
    private function isDpcAh(Request $request, DpcProposal $proposal): bool
    {
        return app(ProposalAllotmentService::class)->isAllotted($request->user(), $proposal, Role::AdministrativeHead);
    }

    /** The AO's correction is not yet right: back to the AO (2026-11-24). */
    public function sendBack(Request $request, DpcProposal $dpcProposal, Grievance $grievance): RedirectResponse
    {
        $this->assertBelongs($dpcProposal, $grievance);
        $data = $request->validate(['instructions' => ['required', 'string', 'max:2000']]);

        try {
            $this->reviews->sendBack($grievance, $data['instructions'], $request->user());
        } catch (WorkflowException $e) {
            return back()->withErrors(['grievance' => $e->getMessage()]);
        }

        return back()->with('status', __('candidate.grievance.review.sent'));
    }

    /** The AH approves a candidate's corrections, after the eSign (2026-11-24). */
    public function approveCandidate(Request $request, DpcProposal $dpcProposal, VerificationParticipant $participant): RedirectResponse
    {
        abort_unless($participant->cycle->dpc_proposal_id === $dpcProposal->id, 404);
        $data = $request->validate(['remark' => ['required', 'string', 'max:2000']]);
        $waiting = Grievance::query()->where('dpc_verification_participant_id', $participant->id)->where('status', GrievanceStatus::ReturnedFromAo->value)->exists();
        if (! $this->isDpcAh($request, $dpcProposal)) {
            return back()->withErrors(['grievance' => __('candidate.grievance.errors.not_yours')]);
        }
        if (! $this->reviews->actionable($dpcProposal) || ! $waiting) {
            return back()->withErrors(['grievance' => __('candidate.grievance.errors.nothing_to_approve')]);
        }

        return app(EsignGate::class)->begin($request, $request->user(), ApproveGrievanceCorrections::class, [
            'proposal' => $dpcProposal->uuid, 'participant' => $participant->uuid, 'remark' => trim($data['remark']),
        ]);
    }

    /** The AO sends a candidate's corrections back to the AH together (2026-11-24). */
    public function submitCandidate(Request $request, DpcProposal $dpcProposal, VerificationParticipant $participant): RedirectResponse
    {
        abort_unless($participant->cycle->dpc_proposal_id === $dpcProposal->id, 404);
        $data = $request->validate(['ao_remarks' => ['required', 'string', 'max:2000']]);

        try {
            $count = $this->reviews->submitCandidate($participant, $data['ao_remarks'], $request->user());
        } catch (WorkflowException $e) {
            return back()->withErrors(['grievance' => $e->getMessage()]);
        }

        return back()->with('status', __('candidate.grievance.review.candidate_submitted', ['count' => $count, 'name' => $participant->row->name]));
    }

    /**
     * The AO's own entry point (2026-11-24): every DPC allotted to them with a grievance
     * section opened for correction, candidate by candidate.
     */
    public function corrections(Request $request, ProposalAllotmentService $allotments): View
    {
        $user = $request->user();
        $proposals = $allotments->scopeTo(DpcProposal::query(), $user)
            ->whereIn('id', VerificationCycle::query()->whereIn('id', Grievance::query()->whereIn('status', [GrievanceStatus::SentToAo->value, GrievanceStatus::ReturnedFromAo->value, GrievanceStatus::Resolved->value])->whereHas('corrections')->select('dpc_verification_cycle_id'))->select('dpc_proposal_id'))
            ->with('department', 'promotionFromPost', 'promotionToPost')
            ->get();

        $rows = $proposals->map(function (DpcProposal $proposal): array {
            $open = GrievanceCorrection::query()->where('status', GrievanceCorrection::OPEN)
                ->whereHas('grievance.cycle', fn ($q) => $q->where('dpc_proposal_id', $proposal->id));

            return [
                'proposal' => $proposal,
                'to_correct' => (clone $open)->count(),
                'candidates' => (clone $open)->with('grievance')->get()->pluck('grievance.dpc_verification_participant_id')->unique()->count(),
                'with_ah' => Grievance::query()->whereHas('cycle', fn ($q) => $q->where('dpc_proposal_id', $proposal->id))->where('status', GrievanceStatus::ReturnedFromAo->value)->count(),
            ];
        })->sortByDesc('to_correct')->values();

        return view('dpc-proposals.grievance-corrections', ['rows' => $rows]);
    }

    public function submitCorrection(Request $request, DpcProposal $dpcProposal, GrievanceCorrection $correction): RedirectResponse
    {
        abort_unless($correction->grievance->cycle->dpc_proposal_id === $dpcProposal->id, 404);
        $data = $request->validate(['ao_remarks' => ['required', 'string', 'max:2000']]);

        try {
            $this->reviews->submitCorrection($correction, $data['ao_remarks'], $request->user());
        } catch (WorkflowException $e) {
            return back()->withErrors(['grievance' => $e->getMessage()]);
        }

        return back()->with('status', __('candidate.grievance.review.correction_submitted'));
    }

    public function document(DpcProposal $dpcProposal, Grievance $grievance): StreamedResponse
    {
        $this->authorize('view', $dpcProposal);
        $this->assertBelongs($dpcProposal, $grievance);
        // Open as well (2026-11-23); a draft never.
        abort_if($grievance->status === GrievanceStatus::Draft || ! Storage::disk($grievance->disk)->exists($grievance->path), 404);

        return Storage::disk($grievance->disk)->download($grievance->path, $grievance->original_name);
    }

    private function assertBelongs(DpcProposal $proposal, Grievance $grievance): void
    {
        abort_unless($grievance->cycle->dpc_proposal_id === $proposal->id, 404);
    }
}
