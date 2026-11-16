<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\IntegrityOption;
use App\Domain\Master\Requests\IntegrityOptionRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Integrity (सत्यनिष्ठा) options (2026-11-14): what the Authorized Officer chooses
 * beside each CR year's grade, and whether the officer is Fit or Unfit on it.
 */
class IntegrityOptionController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.integrity-options.index', [
            'reasons' => $this->searched($request, IntegrityOption::query()
                ->orderBy('sort_order')
                ->orderBy('code'), 'integrity-options')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.integrity-options.form', [
            'reason' => new IntegrityOption,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function store(IntegrityOptionRequest $request): RedirectResponse
    {
        $this->crud->create(new IntegrityOption, $request->validated());

        return redirect()->route('admin.integrity-options.index')
            ->with('status', __('admin.integrity_options.created'));
    }

    public function edit(IntegrityOption $integrityOption): View
    {
        return view('admin.integrity-options.form', [
            'reason' => $integrityOption,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function update(IntegrityOptionRequest $request, IntegrityOption $integrityOption): RedirectResponse
    {
        $this->crud->update($integrityOption, $request->validated());

        return redirect()->route('admin.integrity-options.index')
            ->with('status', __('admin.integrity_options.updated'));
    }

    public function destroy(IntegrityOption $integrityOption): RedirectResponse
    {
        $this->crud->delete($integrityOption);

        return redirect()->route('admin.integrity-options.index')
            ->with('status', __('admin.integrity_options.deleted'));
    }
}
