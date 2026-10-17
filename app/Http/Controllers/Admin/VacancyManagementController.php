<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\Master\Models\DepartmentPost;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Vacancy Management — the Administrator's one entry point to the year-wise vacancy
 * records (2026-10-17).
 *
 * Choose Department → Post → Year and open that record's four steps (sanctioned
 * strength, working strength, vacancies, review and finalize — the existing
 * `VacancyMasterController` screens), or find any record by department, post, year,
 * status or name. The route group is `role:admin`, like every Administrator screen.
 */
class VacancyManagementController extends Controller
{
    public function __construct(private readonly VacancyMasterService $vacancies) {}

    public function index(Request $request): View
    {
        $departmentId = $request->integer('department_id') ?: null;
        $year = $request->integer('year') ?: null;
        $status = $request->string('status')->toString();
        $search = trim($request->string('q')->toString());

        $records = VacancyPosition::query()
            ->with('department')
            // The latest revision of each department / post / year: the record's
            // history is on its own page.
            ->whereRaw('revision = (select max(v2.revision) from vacancy_positions v2 where v2.department_id = vacancy_positions.department_id and v2.post_name = vacancy_positions.post_name and v2.vacancy_year = vacancy_positions.vacancy_year)')
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->when($year !== null, fn ($query) => $query->where('vacancy_year', $year))
            ->when(in_array($status, ['draft', 'finalized'], true), fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where('post_name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('vacancy_year')
            ->orderBy('department_id')
            ->orderBy('post_name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.vacancy-management.index', [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'posts' => $departmentId === null ? collect() : $this->postNames($departmentId),
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
        $mappings = DepartmentPost::query()
            ->where('department_id', $department->id)
            ->where('name', $validated['post'])
            ->orderByDesc('id')
            ->get();

        if ($mappings->isEmpty()) {
            return back()->withInput()->withErrors(['post' => __('vacancymaster.management.post_not_mapped')]);
        }

        $post = $this->vacancies->mappingInForce($mappings, (int) $validated['year']) ?? $mappings->first();

        return redirect()->route('admin.departments.vacancy-master.show', [$department, (int) $validated['year'], $post]);
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
