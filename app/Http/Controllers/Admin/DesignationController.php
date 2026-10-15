<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\Designation;
use App\Domain\Master\Requests\DesignationRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DesignationController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.designations.index', [
            'designations' => $this->searched($request, Designation::query()->orderBy('name'), 'designations')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.designations.form', ['designation' => new Designation, 'statuses' => MasterStatus::cases()]);
    }

    public function store(DesignationRequest $request): RedirectResponse
    {
        $this->crud->create(new Designation, $request->validated());

        return redirect()->route('admin.designations.index')->with('status', 'Designation created.');
    }

    public function edit(Designation $designation): View
    {
        return view('admin.designations.form', ['designation' => $designation, 'statuses' => MasterStatus::cases()]);
    }

    public function update(DesignationRequest $request, Designation $designation): RedirectResponse
    {
        $this->crud->update($designation, $request->validated());

        return redirect()->route('admin.designations.index')->with('status', 'Designation updated.');
    }

    public function destroy(Designation $designation): RedirectResponse
    {
        $this->crud->delete($designation);

        return redirect()->route('admin.designations.index')->with('status', 'Designation deleted.');
    }
}
