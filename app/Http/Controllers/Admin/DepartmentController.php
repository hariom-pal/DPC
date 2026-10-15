<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Department\Models\Department;
use App\Domain\Department\Requests\DepartmentRequest;
use App\Domain\Master\Models\District;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\DepartmentStatus;
use App\Enums\MasterStatus;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Department Master (CGPSC Admin).
 *
 * The list this maintains is the one department registration (PPT S6) offers,
 * and the `code` is the prefix of every DPC ID that department is ever issued
 * (S28/S32). Registration itself is untouched: it still reads the master and
 * still owns the `unregistered → registered` transition. This screen only
 * curates the catalogue.
 *
 * Two guards exist because a department is not a lookup row:
 *
 * - the code becomes immutable once a DPC ID has been issued under it, since
 *   changing it would leave issued IDs pointing at a prefix that no longer
 *   exists;
 * - a department that has been registered, or that anything else references,
 *   is not deletable — it is deactivated instead, which is what withdrawing a
 *   department from the portal actually means.
 */
class DepartmentController extends Controller
{
    use ListsAMaster;

    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(Request $request): View
    {
        return view('admin.departments.index', [
            'departments' => $this->searched($request, Department::query()
                ->with('district')
                ->withCount(['users', 'posts', 'dpcProposals'])
                ->orderBy('name'), 'departments')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.departments.form', [
            'department' => new Department,
            'districts' => $this->districts(),
            'statuses' => DepartmentStatus::cases(),
            'codeLocked' => false,
        ]);
    }

    public function store(DepartmentRequest $request): RedirectResponse
    {
        $this->crud->create(new Department, $request->validated());

        return redirect()->route('admin.departments.index')->with('status', 'Department created.');
    }

    public function edit(Department $department): View
    {
        return view('admin.departments.form', [
            'department' => $department,
            'districts' => $this->districts(),
            'statuses' => DepartmentStatus::cases(),
            'codeLocked' => $this->hasIssuedDpcIds($department),
        ]);
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        $data = $request->validated();

        // The code is the DPC ID prefix. Once an ID has been issued under it,
        // changing it would orphan every ID already in circulation.
        if ($this->hasIssuedDpcIds($department)) {
            unset($data['code']);
        }

        $this->crud->update($department, $data);

        return redirect()->route('admin.departments.index')->with('status', 'Department updated.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        $blockers = $this->deletionBlockers($department);

        if ($blockers !== []) {
            return redirect()
                ->route('admin.departments.index')
                ->withErrors(['department' => 'This department cannot be deleted — '.implode('; ', $blockers)
                    .'. Set its status to Unregistered to withdraw it from the registration list instead.']);
        }

        $this->crud->delete($department);

        return redirect()->route('admin.departments.index')->with('status', 'Department deleted.');
    }

    /**
     * @return Collection<int, District>
     */
    private function districts()
    {
        return District::query()
            ->where('status', MasterStatus::Active->value)
            ->orderBy('name_en')
            ->get();
    }

    private function hasIssuedDpcIds(Department $department): bool
    {
        return $department->dpcIdSequences()->exists() || $department->dpcProposals()->exists();
    }

    /**
     * @return list<string>
     */
    private function deletionBlockers(Department $department): array
    {
        $blockers = [];

        if ($department->status !== DepartmentStatus::Unregistered) {
            $blockers[] = 'it is already registered on the portal';
        }

        if ($department->users()->exists()) {
            $blockers[] = 'officer accounts belong to it';
        }

        if ($department->posts()->exists()) {
            $blockers[] = 'posts are defined under it';
        }

        if ($this->hasIssuedDpcIds($department)) {
            $blockers[] = 'DPC proposals have been raised under it';
        }

        return $blockers;
    }
}
