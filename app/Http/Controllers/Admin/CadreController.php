<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Requests\CadreRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class CadreController extends Controller
{
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.cadres.index', [
            'cadres' => Cadre::query()->with(['department', 'payScale'])->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.cadres.form', $this->formData(new Cadre));
    }

    public function store(CadreRequest $request): RedirectResponse
    {
        $this->crud->create(new Cadre, $request->validated());

        return redirect()->route('admin.cadres.index')->with('status', 'Cadre created.');
    }

    public function edit(Cadre $cadre): View
    {
        return view('admin.cadres.form', $this->formData($cadre));
    }

    public function update(CadreRequest $request, Cadre $cadre): RedirectResponse
    {
        $this->crud->update($cadre, $request->validated());

        return redirect()->route('admin.cadres.index')->with('status', 'Cadre updated.');
    }

    public function destroy(Cadre $cadre): RedirectResponse
    {
        $this->crud->delete($cadre);

        return redirect()->route('admin.cadres.index')->with('status', 'Cadre deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Cadre $cadre): array
    {
        return [
            'cadre' => $cadre,
            'departments' => Department::query()->orderBy('name')->get(),
            'payScales' => PayScale::query()->where('status', MasterStatus::Active->value)->orderBy('label')->get(),
            'classes' => ServiceClass::cases(),
            'statuses' => MasterStatus::cases(),
        ];
    }
}
