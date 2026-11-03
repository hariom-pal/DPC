<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\Employee\Models\Employee;
use App\Domain\Master\Models\DepartmentPost;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Vacancy Management — the one entry point to the year-wise vacancy records.
 *
 * Since 2026-10-19 the department's Authorized Officer keeps and finalizes them (own
 * department only) and its Administrative Head reads them. Since 2026-11-02 it is in
 * neither the top menu nor the Administrator's area: a record is reached from its
 * approved Pre-DPC.
 *
 * Choose Department → Post → Year and open that record's four steps (sanctioned
 * strength, working strength, vacancies, review and finalize — the existing
 * `VacancyMasterController` screens), or find any record by department, post, year,
 * status or name.
 */
class VacancyManagementController extends Controller
{
    public function __construct(private readonly VacancyMasterService $vacancies) {}

    public function index(Request $request): View
    {
        $own = $this->ownDepartmentId($request);
        // A department's officers see their own department only.
        $departmentId = $own;
        $year = $request->integer('year') ?: null;
        $status = $request->string('status')->toString();
        $search = trim($request->string('q')->toString());

        $records = VacancyPosition::query()
            ->with('department')
            // The latest revision of each department / post / year: the record's
            // history is on its own page.
            ->whereRaw('revision = (select max(v2.revision) from vacancy_positions v2 where v2.department_id = vacancy_positions.department_id and v2.post_name = vacancy_positions.post_name and v2.vacancy_year = vacancy_positions.vacancy_year)')
            ->where('department_id', $departmentId)
            ->when($year !== null, fn ($query) => $query->where('vacancy_year', $year))
            ->when(in_array($status, ['draft', 'finalized'], true), fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where('post_name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('vacancy_year')
            ->orderBy('department_id')
            ->orderBy('post_name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.vacancy-management.index', [
            ...$this->area($request),
            'departments' => Department::query()->whereKey($own)->orderBy('name')->get(['id', 'name']),
            'posts' => $this->postNames($departmentId),
            'years' => $this->years(),
            'records' => $records,
            'postIds' => $this->postIds($records->getCollection()),
            'filters' => [
                'department_id' => $departmentId,
                'post' => $request->string('post')->toString(),
                'year' => $year,
                'status' => $status,
                'q' => $search,
            ],
            'currentYear' => (int) now()->year,
        ]);
    }

    /**
     * Step 1 of the guided flow: open the record for the chosen Department, Post and
     * Year. Only a post mapped to that department may be chosen.
     */
    public function go(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'post' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'in:'.implode(',', $this->years())],
        ], [], [
            'department_id' => __('vacancymaster.management.department'),
            'post' => __('vacancymaster.management.post'),
            'year' => __('vacancymaster.vacancy_year'),
        ]);

        $department = Department::query()->findOrFail($validated['department_id']);
        $own = $this->ownDepartmentId($request);
        abort_if($own !== $department->id, 403);
        $mappings = DepartmentPost::query()
            ->where('department_id', $department->id)
            ->where('name', $validated['post'])
            ->orderByDesc('id')
            ->get();

        if ($mappings->isEmpty()) {
            return back()->withInput()->withErrors(['post' => __('vacancymaster.management.post_not_mapped')]);
        }

        $post = $this->vacancies->mappingInForce($mappings, (int) $validated['year']) ?? $mappings->first();

        return redirect()->route($this->area($request)['recordsRoute'].'.show', [$department, (int) $validated['year'], $post]);
    }

    /**
     * The officer of the employee database with this Employee ID, in the user's own
     * department (UAT, 2026-11-02) — so a form fills the officer's details in from the
     * Employee ID (Vacancy Management, Working Officers and Seniority List rows).
     */
    public function employee(Request $request): JsonResponse
    {
        $number = mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $request->query('employee_number', '')));

        if ($number === '' || mb_strlen($number) > 60) {
            return response()->json(['found' => false]);
        }

        $employee = Employee::query()
            ->where('department_id', $this->ownDepartmentId($request))
            ->whereRaw("UPPER(REPLACE(employee_number, ' ', '')) = ?", [$number])
            ->first();

        if ($employee === null) {
            return response()->json(['found' => false]);
        }

        // What the department's own forms ask for (2026-11-03): the Working Officers
        // and Seniority List rows fill these in; Vacancy Management takes the name.
        return response()->json([
            'found' => true,
            'employee_number' => $employee->employee_number,
            'name' => $employee->name,
            'mobile' => $employee->mobile,
            'date_of_birth' => $employee->date_of_birth?->format('Y-m-d'),
            'qualification' => $employee->qualification,
            'date_of_joining_service' => $employee->date_of_joining_service?->format('Y-m-d'),
            'date_of_retirement' => $employee->date_of_retirement?->format('Y-m-d'),
        ]);
    }

    /**
     * A department's officer sees only their own department.
     */
    private function ownDepartmentId(Request $request): int
    {
        $id = $request->user()?->department_id;
        abort_if($id === null, 403);

        return (int) $id;
    }

    /**
     * @return array{recordsRoute: string, management: string, layout: string, canManage: bool}
     */
    private function area(Request $request): array
    {
        return [
            'recordsRoute' => 'vacancy-records',
            'management' => 'vacancy-management',
            'layout' => 'layouts.app',
            'canManage' => $request->user()?->hasRole(Role::AuthorizedOfficer->value) ?? false,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function postNames(int $departmentId): Collection
    {
        return DepartmentPost::query()
            ->where('department_id', $departmentId)
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values();
    }

    /**
     * The mapping row to open each listed record with (department id + post name → id).
     *
     * @param  Collection<int, VacancyPosition>  $records
     * @return array<string, int>
     */
    private function postIds(Collection $records): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        return DepartmentPost::query()
            ->whereIn('department_id', $records->pluck('department_id')->unique())
            ->whereIn('name', $records->pluck('post_name')->unique())
            ->orderBy('id')
            ->get(['id', 'department_id', 'name'])
            ->mapWithKeys(fn (DepartmentPost $post): array => [$post->department_id.'|'.$post->name => $post->id])
            ->all();
    }

    /**
     * @return list<int>
     */
    private function years(): array
    {
        return range((int) now()->year + 1, (int) config('dpc.vacancy_master.earliest_year', 2019));
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [VacancyPositionStatus::Draft->value, VacancyPositionStatus::Finalized->value];
    }
}
