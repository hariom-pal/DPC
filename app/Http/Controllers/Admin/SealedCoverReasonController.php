<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\SealedCoverReason;
use App\Domain\Master\Requests\SealedCoverReasonRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Sealed Cover reasons (Coder Instructions rule 2.2): what the Authorized Officer
 * chooses when a departmental enquiry, charge-sheet, show-cause or suspension is
 * pending against an officer, and whether it keeps the finding in a sealed cover.
 */
class SealedCoverReasonController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.sealed-cover-reasons.index', [
            'reasons' => $this->searched($request, SealedCoverReason::query()
                ->orderBy('sort_order')
                ->orderBy('code'), 'sealed-cover-reasons')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.sealed-cover-reasons.form', [
            'reason' => new SealedCoverReason,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function store(SealedCoverReasonRequest $request): RedirectResponse
    {
        $this->crud->create(new SealedCoverReason, $request->validated());

        return redirect()->route('admin.sealed-cover-reasons.index')
            ->with('status', __('admin.sealed_cover_reasons.created'));
    }

    public function edit(SealedCoverReason $sealedCoverReason): View
    {
        return view('admin.sealed-cover-reasons.form', [
            'reason' => $sealedCoverReason,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function update(SealedCoverReasonRequest $request, SealedCoverReason $sealedCoverReason): RedirectResponse
    {
        $this->crud->update($sealedCoverReason, $request->validated());

        return redirect()->route('admin.sealed-cover-reasons.index')
            ->with('status', __('admin.sealed_cover_reasons.updated'));
    }

    public function destroy(SealedCoverReason $sealedCoverReason): RedirectResponse
    {
        $this->crud->delete($sealedCoverReason);

        return redirect()->route('admin.sealed-cover-reasons.index')
            ->with('status', __('admin.sealed_cover_reasons.deleted'));
    }
}
