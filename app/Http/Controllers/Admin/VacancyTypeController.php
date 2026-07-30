<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\VacancyType;
use App\Domain\Master\Requests\VacancyTypeRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class VacancyTypeController extends Controller
{
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.vacancy-types.index', [
            'vacancyTypes' => VacancyType::query()->orderBy('code')->paginate(20),
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
