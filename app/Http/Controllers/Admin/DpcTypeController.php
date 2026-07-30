<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Requests\DpcTypeRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class DpcTypeController extends Controller
{
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.dpc-types.index', [
            'dpcTypes' => DpcType::query()->orderBy('code')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.dpc-types.form', ['dpcType' => new DpcType, 'statuses' => MasterStatus::cases()]);
    }

    public function store(DpcTypeRequest $request): RedirectResponse
    {
        $this->crud->create(new DpcType, $request->validated());

        return redirect()->route('admin.dpc-types.index')->with('status', 'DPC type created.');
    }

    public function edit(DpcType $dpcType): View
    {
        return view('admin.dpc-types.form', ['dpcType' => $dpcType, 'statuses' => MasterStatus::cases()]);
    }

    public function update(DpcTypeRequest $request, DpcType $dpcType): RedirectResponse
    {
        $this->crud->update($dpcType, $request->validated());

        return redirect()->route('admin.dpc-types.index')->with('status', 'DPC type updated.');
    }

    public function destroy(DpcType $dpcType): RedirectResponse
    {
        $this->crud->delete($dpcType);

        return redirect()->route('admin.dpc-types.index')->with('status', 'DPC type deleted.');
    }
}
