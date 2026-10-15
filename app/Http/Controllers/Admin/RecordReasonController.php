<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Requests\RecordReasonRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Reason Master — why a mandatory CR or Loyalty record is missing (change
 * register of 2026-08-27, item 16).
 *
 * > *Create a configurable Reason Master.*
 *
 * Configurable is the requirement, so this is ordinary master CRUD rather than an
 * enum: the Commission adds "Awaiting Vigilance Clearance" without a deployment.
 *
 * **Deletion is the shared master delete**, which is a soft one — and it has to be.
 * A gap already citing a reason keeps citing it, because withdrawing the reason today
 * does not change why a record was missing two years ago; the schema enforces that
 * with `restrictOnDelete` on the gap's foreign key. Withdrawal is what
 * {@see RecordReason::scopeSelectable()} reads, and it stops the reason being chosen
 * on anything new.
 */
class RecordReasonController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.record-reasons.index', [
            'reasons' => $this->searched($request, RecordReason::query()
                ->orderBy('sort_order')
                ->orderBy('code'), 'record-reasons')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.record-reasons.form', [
            'reason' => new RecordReason,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function store(RecordReasonRequest $request): RedirectResponse
    {
        $this->crud->create(new RecordReason, $request->validated());

        return redirect()->route('admin.record-reasons.index')
            ->with('status', __('admin.record_reasons.created'));
    }

    public function edit(RecordReason $recordReason): View
    {
        return view('admin.record-reasons.form', [
            'reason' => $recordReason,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function update(RecordReasonRequest $request, RecordReason $recordReason): RedirectResponse
    {
        $this->crud->update($recordReason, $request->validated());

        return redirect()->route('admin.record-reasons.index')
            ->with('status', __('admin.record_reasons.updated'));
    }

    public function destroy(RecordReason $recordReason): RedirectResponse
    {
        $this->crud->delete($recordReason);

        return redirect()->route('admin.record-reasons.index')
            ->with('status', __('admin.record_reasons.deleted'));
    }
}
