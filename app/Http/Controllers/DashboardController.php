<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
        ]);
    }
}
