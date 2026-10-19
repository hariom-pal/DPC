<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\VacancyEntryKind;
use App\Domain\Dpc\Enums\VacancyReason;
use App\Domain\Dpc\Enums\VacancyReconciliation;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use App\Domain\Dpc\Models\VacancyPositionSanctionedStrength;
use App\Domain\Dpc\Models\VacancyPositionWorkingStrength;
use App\Domain\Dpc\Requests\SaveSanctionedStrengthRequest;
use App\Domain\Dpc\Requests\SaveWorkingStrengthRequest;
use App\Domain\Dpc\Requests\StoreVacancyEntryRequest;
use App\Domain\Dpc\Requests\VerifyVacancyEntryRequest;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Vacancy Master, inside Department Management.
 *
 * The department is the one in the URL — there is no department picker — and every
 * post, position, entry and document is checked to belong to it. The whole group is
 * behind `role:admin`; each write also asserts the role here, so the rule does not
 * rest on the route file alone.
 */
class VacancyMasterController extends Controller
{
    public function __construct(private readonly VacancyMasterService $vacancies) {}

    public function index(Request $request, Department $department): View
    {
        $this->assertCanView($request, $department);
        $year = $this->year($request);

        return view('admin.vacancy-master.index', [
            ...$this->context($request, $department),
            'department' => $department,
            'year' => $year,
            'years' => $this->selectableYears(),
            'rows' => collect($this->vacancies->postsFor($department, $year))->map(fn (array $row): array => [
                ...$row,
                'status' => $this->vacancies->statusKey($row['position'], $row['breakdown']),
            ])->all(),
            'seniorityReference' => $this->vacancies->seniorityReferenceDate($year),
        ]);
    }

    /**
     * The four steps of a position, each on its own screen so a user meets one
     * question at a time: what is sanctioned for the year, who is working on 1 January,
     * what falls vacant during the year, and the result.
     */
    public const SECTIONS = ['sanctioned', 'working', 'vacancies', 'summary'];

    public function show(Request $request, Department $department, int $year, DepartmentPost $post): View
    {
        $this->assertCanView($request, $department);
        $this->assertPostInDepartment($department, $post);

        $position = $this->vacancies->current($department->id, $post->name, $year);
        $position?->load(
            'sanctionedStrengthHistory.recorder', 'sanctionedStrength',
            'workingStrengthHistory.recorder', 'workingStrength',
            'entries.recorder', 'entries.verifier', 'entries.higherPost', 'entries.higherDpcProposal',
        );

        return view('admin.vacancy-master.show', [
            ...$this->context($request, $department),
            'section' => $this->section($request, $position),
            'department' => $department,
            'year' => $year,
            'post' => $post,
            'position' => $position,
            'breakdown' => $position === null ? null : $this->vacancies->breakdown($position),
            'revisions' => $revisions = $this->vacancies->revisions($department->id, $post->name, $year),

            // Every recorded step of every revision of this record, newest first:
            // who did what, and when.
            'trail' => ActivityTimeline::query()
                ->where('subject_type', (new VacancyPosition)->getMorphClass())
                ->whereIn('subject_id', $revisions->pluck('id'))
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->get(),
            'higherPosts' => DepartmentPost::query()
                ->where('department_id', $department->id)
                ->where('name', '!=', $post->name)
                ->whereNotNull('active_flag')
                ->orderBy('name')
                ->get(),
            'kinds' => VacancyEntryKind::cases(),
            'reasons' => VacancyReason::cases(),
            'reconciliations' => VacancyReconciliation::cases(),
            'seniorityReference' => $this->vacancies->seniorityReferenceDate($year),
        ]);
    }

    public function open(Request $request, Department $department, int $year, DepartmentPost $post): RedirectResponse
    {
        $this->assertCanManage($request, $department);
        $this->assertPostInDepartment($department, $post);

        return $this->attempt($department, $year, $post, function () use ($department, $post, $year, $request): string {
            $this->vacancies->openDraft($department, $post, $year, $request->user());

            return __('vacancymaster.flash.draft_opened');
        }, 'sanctioned', 'sanctioned');
    }

    public function saveSanctionedStrength(SaveSanctionedStrengthRequest $request, Department $department, int $year, DepartmentPost $post): RedirectResponse
    {
        $this->assertCanManage($request, $department);
        $position = $this->draftFor($department, $year, $post);

        return $this->attempt($department, $year, $post, function () use ($position, $request): string {
            $this->vacancies->saveSanctionedStrength($position, $request->safe()->except('document'), $request->file('document'), $request->user());

            return __('vacancymaster.flash.sanctioned_saved');
        }, 'sanctioned', 'working');
    }

    public function saveWorkingStrength(SaveWorkingStrengthRequest $request, Department $department, int $year, DepartmentPost $post): RedirectResponse
    {
        $this->assertCanManage($request, $department);
        $position = $this->draftFor($department, $year, $post);

        return $this->attempt($department, $year, $post, function () use ($position, $request): string {
            $this->vacancies->saveWorkingStrength($position, [
                ...$request->safe()->except('document'),
                'exceeds_sanctioned_acknowledged' => $request->boolean('exceeds_sanctioned_acknowledged'),
            ], $request->file('document'), $request->user());

            return __('vacancymaster.flash.working_saved');
        }, 'working', 'vacancies');
    }

    public function storeEntry(StoreVacancyEntryRequest $request, Department $department, int $year, DepartmentPost $post): RedirectResponse
    {
        $this->assertCanManage($request, $department);
        $position = $this->draftFor($department, $year, $post);

        return $this->attempt($department, $year, $post, function () use ($position, $request): string {
            $this->vacancies->addEntry($position, $request->safe()->except('document'), $request->file('document'), $request->user());

            return __('vacancymaster.flash.entry_added');
        }, 'vacancies', 'vacancies');
    }

    public function verifyEntry(VerifyVacancyEntryRequest $request, Department $department, int $year, DepartmentPost $post, VacancyPositionEntry $entry): RedirectResponse
    {
        $this->assertCanManage($request, $department);
        $this->assertEntryInPost($department, $year, $post, $entry);

        return $this->attempt($department, $year, $post, function () use ($entry, $request): string {
            $this->vacancies->verifyEntry($entry, $request->validated(), $request->user());

            return __('vacancymaster.flash.entry_verified');
        }, 'vacancies', 'vacancies', VerifyVacancyEntryRequest::bagFor($entry));
    }

    public function withdrawEntry(Request $request, Department $department, int $year, DepartmentPost $post, VacancyPositionEntry $entry): RedirectResponse
    {
        $this->assertCanManage($request, $department);
        $this->assertEntryInPost($department, $year, $post, $entry);
        $data = $request->validateWithBag(VerifyVacancyEntryRequest::bagFor($entry), [
            'withdrawal_reason' => ['required', 'string', 'max:2000'],
        ], [], ['withdrawal_reason' => __('vacancymaster.entries.withdrawal_reason')]);

        return $this->attempt($department, $year, $post, function () use ($entry, $data, $request): string {
            $this->vacancies->withdrawEntry($entry, $data['withdrawal_reason'], $request->user());

            return __('vacancymaster.flash.entry_withdrawn');
        }, 'vacancies', 'vacancies', VerifyVacancyEntryRequest::bagFor($entry));
    }

    public function finalize(Request $request, Department $department, int $year, DepartmentPost $post): RedirectResponse
    {
        $this->assertCanManage($request, $department);
        $position = $this->draftFor($department, $year, $post);

        return $this->attempt($department, $year, $post, function () use ($position, $request): string {
            $this->vacancies->finalize($position, $request->user());

            return __('vacancymaster.flash.finalized');
        }, 'summary', 'summary');
    }

    public function revise(Request $request, Department $department, int $year, DepartmentPost $post): RedirectResponse
    {
        $this->assertCanManage($request, $department);
        $this->assertPostInDepartment($department, $post);
        $data = $request->validate(['revision_reason' => ['required', 'string', 'max:2000']], [], ['revision_reason' => __('vacancymaster.revision_reason_label')]);
        $position = $this->vacancies->current($department->id, $post->name, $year);
        abort_if($position === null, 404);

        return $this->attempt($department, $year, $post, function () use ($position, $data, $request): string {
            $this->vacancies->revise($position, $data['revision_reason'], $request->user());

            return __('vacancymaster.flash.revision_opened');
        }, 'summary', 'summary');
    }

    public function sanctionedStrengthDocument(Department $department, int $year, DepartmentPost $post, VacancyPositionSanctionedStrength $sanctionedStrength): StreamedResponse
    {
        $this->assertCanView(request(), $department);
        $this->assertPostInDepartment($department, $post);
        $this->assertPositionInPost($department, $year, $post, $sanctionedStrength->position);

        return $this->stream((string) $sanctionedStrength->disk, (string) $sanctionedStrength->path, (string) $sanctionedStrength->original_name);
    }

    public function workingStrengthDocument(Department $department, int $year, DepartmentPost $post, VacancyPositionWorkingStrength $workingStrength): StreamedResponse
    {
        $this->assertCanView(request(), $department);
        $this->assertPostInDepartment($department, $post);
        $this->assertPositionInPost($department, $year, $post, $workingStrength->position);

        return $this->stream((string) $workingStrength->disk, (string) $workingStrength->path, (string) $workingStrength->original_name);
    }

    public function entryDocument(Department $department, int $year, DepartmentPost $post, VacancyPositionEntry $entry): StreamedResponse
    {
        $this->assertCanView(request(), $department);
        $this->assertEntryInPost($department, $year, $post, $entry);

        return $this->stream((string) $entry->disk, (string) $entry->path, (string) $entry->original_name);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Run a write, then go to the next step on success or stay on the same step to
     * show what went wrong.
     */
    /**
     * A refused rule goes back to the step it came from, keyed by the field it is about
     * (or `workflow` when it is about no one field), in `$bag` — each entry's own forms
     * use their own bag, so a message never appears beside another form's field.
     */
    private function attempt(Department $department, int $year, DepartmentPost $post, callable $action, string $here, string $next, string $bag = 'default'): RedirectResponse
    {
        $records = $this->context(request(), $department)['records'];
        $target = fn (string $section): string => route($records.'.show', [$department, $year, $post, 'section' => $section]);

        try {
            $message = $action();
        } catch (WorkflowException $e) {
            return redirect()->to($target($here))->withErrors([$e->field ?? 'workflow' => $e->getMessage()], $bag)->withInput();
        }

        return redirect()->to($target($next))->with('status', $message);
    }

    /**
     * The step asked for, or — when none is — the first step that still needs work.
     */
    private function section(Request $request, ?VacancyPosition $position): string
    {
        $asked = (string) $request->query('section', '');

        if (in_array($asked, self::SECTIONS, true)) {
            return $asked;
        }

        if ($position === null || ! $position->isDraft()) {
            return $position === null ? 'sanctioned' : 'summary';
        }

        if ($position->sanctionedStrength === null) {
            return 'sanctioned';
        }

        return $position->workingStrength === null ? 'working' : 'vacancies';
    }

    private function draftFor(Department $department, int $year, DepartmentPost $post): VacancyPosition
    {
        $this->assertPostInDepartment($department, $post);
        $position = $this->vacancies->current($department->id, $post->name, $year);

        abort_if($position === null, 404);

        return $position;
    }

    /**
     * Who may see a department's vacancy records: the CGPSC Administrator (every
     * department, read-only), and the department's own Authorized Officer and
     * Administrative Head.
     */
    private function assertCanView(Request $request, Department $department): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->hasRole(Role::Admin->value)
                || ($user->department_id === $department->id
                    && $user->hasAnyRole([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value]))),
            403,
        );
    }

    /**
     * Who may change them (2026-10-19): the department's own Authorized Officer, who
     * also finalizes. The Administrator's screens are read-only.
     */
    private function canManage(Request $request, Department $department): bool
    {
        $user = $request->user();

        return $user !== null
            && ! $request->routeIs('admin.*')
            && $user->hasRole(Role::AuthorizedOfficer->value)
            && $user->department_id === $department->id;
    }

    private function assertCanManage(Request $request, Department $department): void
    {
        abort_unless($this->canManage($request, $department), 403);
    }

    /**
     * The area the screen is shown in — the Administrator's (read-only) or the
     * department's — so links, redirects and the layout stay in it.
     *
     * @return array{records: string, management: string, layout: string, canManage: bool, adminArea: bool}
     */
    private function context(Request $request, Department $department): array
    {
        $admin = $request->routeIs('admin.*');

        return [
            'records' => $admin ? 'admin.departments.vacancy-master' : 'vacancy-records',
            'management' => $admin ? 'admin.vacancy-management' : 'vacancy-management',
            'layout' => $admin ? 'layouts.admin' : 'layouts.app',
            'canManage' => $this->canManage($request, $department),
            'adminArea' => $admin,
        ];
    }

    private function assertPostInDepartment(Department $department, DepartmentPost $post): void
    {
        abort_unless($post->department_id === $department->id, 404);
    }

    private function assertPositionInPost(Department $department, int $year, DepartmentPost $post, ?VacancyPosition $position): void
    {
        abort_unless(
            $position !== null
                && $position->department_id === $department->id
                && $position->post_name === $post->name
                && $position->vacancy_year === $year,
            404,
        );
    }

    private function assertEntryInPost(Department $department, int $year, DepartmentPost $post, VacancyPositionEntry $entry): void
    {
        $this->assertPostInDepartment($department, $post);
        $this->assertPositionInPost($department, $year, $post, $entry->position);
    }

    private function stream(string $disk, string $path, string $name): StreamedResponse
    {
        $storage = Storage::disk($disk);

        abort_unless($storage->exists($path), 404);

        return $storage->download($path, $name, ['Content-Type' => 'application/pdf']);
    }

    private function year(Request $request): int
    {
        $year = (int) $request->query('year', (string) now()->year);
        $years = $this->selectableYears();

        return in_array($year, $years, true) ? $year : (int) now()->year;
    }

    /**
     * @return list<int>
     */
    private function selectableYears(): array
    {
        return range((int) now()->year + 1, (int) config('dpc.vacancy_master.earliest_year', 2019));
    }
}
