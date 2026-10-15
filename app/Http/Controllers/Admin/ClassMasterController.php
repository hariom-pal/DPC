<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\ClassMaster;
use App\Domain\Master\Requests\ClassMasterRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Class Master (change item 1) — the CGPSC Administrator's control over how the
 * service classes are named, ordered and whether each is still in use.
 *
 * See `ClassMaster` and its migration for what is *not* editable here and why.
 */
class ClassMasterController extends Controller
{
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.service-classes.index', [
            'classes' => ClassMaster::query()->orderBy('sort_order')->orderBy('code')->paginate(20),

            /*
             | Whether "New Class" is offered at all.
             |
             | The code set is statutory and the migration seeds every one of
             | them, so on a healthy installation there is nothing to add and the
             | button would only ever produce a uniqueness error. It reappears if
             | a class has genuinely been removed from the master, which is the
             | one case where adding is the right repair.
             */
            'canAddMore' => ClassMaster::query()->withTrashed()->count() < count(ServiceClass::cases()),
        ]);
    }

    public function create(): View
    {
        return view('admin.service-classes.form', $this->formData(new ClassMaster));
    }

    public function store(ClassMasterRequest $request): RedirectResponse
    {
        $this->crud->create(new ClassMaster, $request->validated());

        return redirect()->route('admin.service-classes.index')->with('status', 'Class added to the master.');
    }

    public function edit(ClassMaster $classMaster): View
    {
        return view('admin.service-classes.form', $this->formData($classMaster));
    }

    public function update(ClassMasterRequest $request, ClassMaster $classMaster): RedirectResponse
    {
        $this->crud->update($classMaster, $request->validated());

        return redirect()->route('admin.service-classes.index')->with('status', 'Class updated. Every screen shows the new name immediately.');
    }

    public function destroy(ClassMaster $classMaster): RedirectResponse
    {
        $this->crud->delete($classMaster);

        return redirect()->route('admin.service-classes.index')
            ->with('status', 'Class withdrawn. Posts and proposals that already cite it still display it.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(ClassMaster $classMaster): array
    {
        return [
            'classMaster' => $classMaster,

            // Offered as a closed list rather than a text box: see
            // `ClassMasterRequest` for why a code that the scoring engine has no
            // zone rule for is worse than a validation error.
            'codes' => array_column(ServiceClass::cases(), 'value'),

            'statuses' => MasterStatus::cases(),
        ];
    }
}
