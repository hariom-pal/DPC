<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cgpsc;

use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Requests\OpenSealedCoverRequest;
use App\Domain\Proceeding\Services\SealedCoverService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Sealed covers of an approved DPC, opened by its Chairman (2026-11-05, BD-48).
 */
class SealedCoverController extends Controller
{
    public function __construct(private readonly SealedCoverService $covers) {}

    public function index(Request $request, DpcProceeding $proceeding): View
    {
        $proposal = $proceeding->proposal;
        abort_unless($proposal !== null && $this->covers->isDpcChairman($proposal, $request->user()), 403);

        return view('cgpsc.proceedings.sealed-covers', [
            'proceeding' => $proceeding,
            'proposal' => $proposal,
            'sealed' => $this->covers->sealed($proceeding),
        ]);
    }

    public function open(OpenSealedCoverRequest $request, DpcProceeding $proceeding): RedirectResponse
    {
        $document = $request->file('document');
        abort_unless($document instanceof UploadedFile, 422);

        try {
            $this->covers->open($proceeding, $request->integer('sn'), $request->validated(), $document, $request->user());
        } catch (WorkflowException $e) {
            return back()->withInput()->withErrors(['sealed_cover' => $e->getMessage()]);
        }

        return redirect()->route('cgpsc.proceedings.sealed-covers', $proceeding)
            ->with('status', __('dpc.sealed_cover.opened'));
    }
}
