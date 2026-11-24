<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\CandidateVerification\Services\GrievanceReviewService;
use App\Domain\Dpc\Services\DpcStageService;
use App\Domain\PreDpc\Services\PreDpcPendingService;
use App\Domain\Shared\Services\DashboardSummaryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardSummaryService $summary) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'user' => $user,
            // Read-only counts. The screens each card links to remain the
            // authority on what may actually be done there.
            'summaries' => $this->summary->for($user),
            // MoM of 16 November 2026, point 8.
            'pendingWith' => app(DpcStageService::class)->pendingWith($user),
            'pendingAt' => app(DpcStageService::class)->pendingAt($user),
            // The Pre-DPC and its Vacancy Management record, one task (2026-11-19).
            'pendingPreDpc' => app(PreDpcPendingService::class)->pendingWith($user),
            // The candidates' grievances waiting on this AH or AO (2026-11-24).
            'grievancesWaiting' => app(GrievanceReviewService::class)->waitingOn($user),
        ]);
    }
}
