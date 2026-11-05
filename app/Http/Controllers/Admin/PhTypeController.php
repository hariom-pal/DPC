<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\PhType;
use App\Domain\Master\Requests\PhTypeRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The PH Type master (2026-11-05): ordinary master CRUD, so the Commission adds or
 * corrects a type without a deployment. Deletion is the shared soft delete; an
 * officer already recorded with a type keeps it.
 */
class PhTypeController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.ph-types.index', [
            'types' => $this->searched($request, PhType::query()
                ->orderBy('sort_order')
                ->orderBy('code'), 'ph-types')
                ->paginate(30)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.ph-types.form', [
            'type' => new PhType,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function store(PhTypeRequest $request): RedirectResponse
    {
        $this->crud->create(new PhType, $request->validated());

        return redirect()->route('admin.ph-types.index')
            ->with('status', __('admin.ph_types.created'));
    }

    public function edit(PhType $phType): View
    {
        return view('admin.ph-types.form', [
            'type' => $phType,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function update(PhTypeRequest $request, PhType $phType): RedirectResponse
    {
        $this->crud->update($phType, $request->validated());

        return redirect()->route('admin.ph-types.index')
            ->with('status', __('admin.ph_types.updated'));
    }

    public function destroy(PhType $phType): RedirectResponse
    {
        $this->crud->delete($phType);

        return redirect()->route('admin.ph-types.index')
            ->with('status', __('admin.ph_types.deleted'));
    }
}
