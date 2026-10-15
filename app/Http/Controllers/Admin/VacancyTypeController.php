<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\VacancyType;
use App\Domain\Master\Requests\VacancyTypeRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VacancyTypeController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.vacancy-types.index', [
            'vacancyTypes' => $this->searched($request, VacancyType::query()->orderBy('code'), 'vacancy-types')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.vacancy-types.form', ['vacancyType' => new VacancyType, 'statuses' => MasterStatus::cases()]);
    }

    public function store(VacancyTypeRequest $request): RedirectResponse
    {
        $this->crud->create(new VacancyType, $request->validated());

        return redirect()->route('admin.vacancy-types.index')->with('status', 'Vacancy type created.');
    }

    public function edit(VacancyType $vacancyType): View
    {
        return view('admin.vacancy-types.form', ['vacancyType' => $vacancyType, 'statuses' => MasterStatus::cases()]);
    }

    public function update(VacancyTypeRequest $request, VacancyType $vacancyType): RedirectResponse
    {
        $this->crud->update($vacancyType, $request->validated());

        return redirect()->route('admin.vacancy-types.index')->with('status', 'Vacancy type updated.');
    }

    public function destroy(VacancyType $vacancyType): RedirectResponse
    {
        $this->crud->delete($vacancyType);

        return redirect()->route('admin.vacancy-types.index')->with('status', 'Vacancy type deleted.');
    }
}
