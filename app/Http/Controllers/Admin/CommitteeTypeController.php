<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\CommitteeType;
use App\Domain\Master\Requests\CommitteeTypeRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CommitteeTypeController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.committee-types.index', [
            'committeeTypes' => $this->searched($request, CommitteeType::query()->orderBy('code'), 'committee-types')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.committee-types.form', ['committeeType' => new CommitteeType, 'statuses' => MasterStatus::cases()]);
    }

    public function store(CommitteeTypeRequest $request): RedirectResponse
    {
        $this->crud->create(new CommitteeType, $request->validated());

        return redirect()->route('admin.committee-types.index')->with('status', 'Committee type created.');
    }

    public function edit(CommitteeType $committeeType): View
    {
        return view('admin.committee-types.form', ['committeeType' => $committeeType, 'statuses' => MasterStatus::cases()]);
    }

    public function update(CommitteeTypeRequest $request, CommitteeType $committeeType): RedirectResponse
    {
        $this->crud->update($committeeType, $request->validated());

        return redirect()->route('admin.committee-types.index')->with('status', 'Committee type updated.');
    }

    public function destroy(CommitteeType $committeeType): RedirectResponse
    {
        $this->crud->delete($committeeType);

        return redirect()->route('admin.committee-types.index')->with('status', 'Committee type deleted.');
    }
}
