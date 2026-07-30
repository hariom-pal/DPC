<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\CommitteeType;
use App\Domain\Master\Requests\CommitteeTypeRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class CommitteeTypeController extends Controller
{
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.committee-types.index', [
            'committeeTypes' => CommitteeType::query()->orderBy('code')->paginate(20),
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
