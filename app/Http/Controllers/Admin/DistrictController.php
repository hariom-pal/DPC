<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\District;
use App\Domain\Master\Requests\DistrictRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DistrictController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.districts.index', [
            'districts' => $this->searched($request, District::query()->orderBy('name_en'), 'districts')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.districts.form', [
            'district' => new District,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function store(DistrictRequest $request): RedirectResponse
    {
        $this->crud->create(new District, $request->validated());

        return redirect()->route('admin.districts.index')->with('status', 'District created.');
    }

    public function edit(District $district): View
    {
        return view('admin.districts.form', [
            'district' => $district,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function update(DistrictRequest $request, District $district): RedirectResponse
    {
        $this->crud->update($district, $request->validated());

        return redirect()->route('admin.districts.index')->with('status', 'District updated.');
    }

    public function destroy(District $district): RedirectResponse
    {
        $this->crud->delete($district);

        return redirect()->route('admin.districts.index')->with('status', 'District deleted.');
    }
}
