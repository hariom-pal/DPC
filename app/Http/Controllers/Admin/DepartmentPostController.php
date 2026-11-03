<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DepartmentPostChange;
use App\Domain\Master\Models\DepartmentPostOrder;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Requests\DepartmentPostRequest;
use App\Domain\Master\Requests\SupersedeDepartmentPostRequest;
use App\Domain\Master\Services\DepartmentPostChangeService;
use App\Domain\Master\Services\DepartmentPostMappingService;
use App\Domain\Master\Services\MasterCrudService;
use App\Domain\UserManagement\Services\DepartmentUserAuthority;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Enums\ServiceClass;
use App\Http\Controllers\Admin\Concerns\ListsAMaster;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Department Post Mapping master — Department + Post + Pay Scale + Class +
 * Promotion Duration, authorised by a Government Order and effective-dated.
 *
 * Superseding is deliberately **not** an edit: see {@see DepartmentPostMappingService}.
 */
class DepartmentPostController extends Controller
{
    use ListsAMaster;

    /**
     * The repeater's own input, which is never mass-assigned onto the mapping.
     *
     * The orders are rows in `department_post_orders` and the uploads are files;
     * neither is a column on `department_posts`, and passing them to the master CRUD
     * service would either be ignored or — if a column of that name ever appeared —
     * silently written.
     *
     * @var list<string>
     */
    private const ORDER_FIELDS = ['orders', 'government_order_document'];

    public function __construct(
        private readonly MasterCrudService $crud,
        private readonly DepartmentPostMappingService $mappings,
        private readonly DepartmentPostChangeService $changes,
    ) {}

    public function index(Request $request): View
    {
        $scope = $this->scope($request);
        // The Administrator sees every department's, and narrows to one (2026-11-03).
        $filter = $scope === null && $request->filled('department_id') ? $request->integer('department_id') : null;

        return $this->page($request, 'admin.department-posts.index', [
            'departmentFilter' => $filter,
            'filterDepartments' => $scope === null ? Department::query()->orderBy('name')->get(['id', 'name']) : collect(),
            // The Authorized Officer's requests awaiting the Department Admin (2026-11-03).
            'pendingChanges' => DepartmentPostChange::query()->pending()
                ->with(['department', 'post', 'requester'])
                ->when($scope !== null, fn ($q) => $q->where('department_id', $scope))
                ->when($filter !== null, fn ($q) => $q->where('department_id', $filter))
                ->oldest()
                ->get(),
            // ...and, for the Authorized Officer, what was decided lately.
            'decidedChanges' => $request->routeIs('post-mapping.*')
                ? DepartmentPostChange::query()->where('status', '!=', DepartmentPostChange::STATUS_PENDING)
                    ->with(['post', 'decider'])
                    ->where('department_id', $scope)
                    ->latest('decided_at')
                    ->limit(10)
                    ->get()
                : collect(),
            'needsApproval' => $this->needsApproval($request),
            'canApprove' => $request->routeIs('department.*'),
            // Mappings in force first, then the superseded ones beneath them, so
            // the screen reads as "what applies now, and what applied before".
            'posts' => $this->searched($request, DepartmentPost::query()
                ->with(['department', 'payScale', 'orders'])
                ->when($scope !== null, fn ($q) => $q->where('department_id', $scope))
                ->when($filter !== null, fn ($q) => $q->where('department_id', $filter))
                // In force, then Draft / Inactive, then superseded (2026-10-30).
                ->orderByRaw('CASE WHEN active_flag IS NOT NULL THEN 0 WHEN effective_to IS NULL THEN 1 ELSE 2 END')
                ->orderBy('name')
                ->orderByDesc('effective_from'), 'department-posts')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->assertCanWrite($request);

        return $this->page($request, 'admin.department-posts.form', $this->formData(new DepartmentPost, $this->scope($request)));
    }

    public function store(DepartmentPostRequest $request): RedirectResponse
    {
        $this->assertCanWrite($request);
        $scope = $this->scope($request);
        abort_if($scope !== null && $request->integer('department_id') !== $scope, 403);

        if ($this->needsApproval($request)) {
            return $this->propose($request, DepartmentPostChange::ACTION_CREATE, null, $this->formPayload($request), $this->orderFiles($request));
        }

        try {
            // The mapping and its orders together (2026-10-29): an order whose file
            // cannot be stored no longer leaves the mapping saved behind an error page.
            $post = DB::transaction(function () use ($request): DepartmentPost {
                $post = $this->crud->create(new DepartmentPost, $request->safe()->except(self::ORDER_FIELDS));
                $this->recordOrders($request, $post);

                return $post;
            });
        } catch (FilesystemException $e) {
            return $this->storageFailed($e);
        }

        return redirect()->route($this->prefix($request).'.show', $post)
            ->with('status', __('admin.department_posts.created'));
    }

    /**
     * The mapping in full — its terms, and every Government Order that bears on
     * it (change register of 2026-08-26, item 10).
     */
    public function show(Request $request, DepartmentPost $post): View
    {
        $this->assertManages($request, $post);
        $post->load(['department', 'payScale', 'orders.uploader']);

        return $this->page($request, 'admin.department-posts.show', [
            'post' => $post,

            // The chain this mapping belongs to: the same post in the same
            // department, whatever the order behind each version.
            'history' => DepartmentPost::query()
                ->where('department_id', $post->department_id)
                ->where('name', $post->name)
                ->whereKeyNot($post->getKey())
                ->orderByDesc('effective_from')
                ->get(),
        ]);
    }

    public function edit(Request $request, DepartmentPost $post): View
    {
        $this->assertCanWrite($request);
        $this->assertManages($request, $post);
        // A superseded mapping is history; a new order supersedes the one in force.
        abort_if($post->isSuperseded(), 403);

        return $this->page($request, 'admin.department-posts.form', $this->formData($post, $this->scope($request)));
    }

    public function update(DepartmentPostRequest $request, DepartmentPost $post): RedirectResponse
    {
        $this->assertCanWrite($request);
        $this->assertManages($request, $post);
        abort_if($post->isSuperseded(), 403);
        $scope = $this->scope($request);
        abort_if($scope !== null && $request->integer('department_id') !== $scope, 403);

        if ($this->needsApproval($request)) {
            return $this->propose($request, DepartmentPostChange::ACTION_UPDATE, $post, $this->formPayload($request), $this->orderFiles($request));
        }

        try {
            DB::transaction(function () use ($request, $post): void {
                $this->crud->update($post, $request->safe()->except(self::ORDER_FIELDS));
                $this->recordOrders($request, $post);
            });
        } catch (FilesystemException $e) {
            return $this->storageFailed($e);
        }

        return redirect()->route($this->prefix($request).'.show', $post)
            ->with('status', __('admin.department_posts.updated'));
    }

    /**
     * A new Government Order for the same post: close this mapping, open its
     * successor.
     */
    public function supersedeForm(Request $request, DepartmentPost $post): View
    {
        $this->assertCanWrite($request);
        $this->assertManages($request, $post);

        return $this->page($request, 'admin.department-posts.supersede', $this->formData($post, $this->scope($request)));
    }

    public function supersede(SupersedeDepartmentPostRequest $request, DepartmentPost $post): RedirectResponse
    {
        $this->assertCanWrite($request);
        $this->assertManages($request, $post);

        if ($this->needsApproval($request)) {
            return $this->propose(
                $request,
                DepartmentPostChange::ACTION_SUPERSEDE,
                $post,
                $request->safe()->except('government_order_document'),
                ['government_order_document' => $request->file('government_order_document')],
            );
        }

        try {
            $replacement = $this->mappings->supersede(
                $post,
                $request->safe()->except('government_order_document'),
                $request->user(),
                $request->file('government_order_document'),
            );
        } catch (FilesystemException $e) {
            return $this->storageFailed($e);
        } catch (RuntimeException $e) {
            // The service's refusals are statements about the order of orders, so
            // they belong on the field the Administrator can act on.
            return back()->withInput()->withErrors(['effective_from' => $e->getMessage()]);
        }

        return redirect()->route($this->prefix($request).'.show', $replacement)
            ->with('status', __('admin.department_posts.superseded'));
    }

    public function destroy(Request $request, DepartmentPost $post): RedirectResponse
    {
        $this->assertCanWrite($request);
        $this->assertManages($request, $post);

        if ($this->needsApproval($request)) {
            return $this->propose($request, DepartmentPostChange::ACTION_DELETE, $post, [], []);
        }

        $this->crud->delete($post);

        return redirect()->route($this->prefix($request).'.index')
            ->with('status', __('admin.department_posts.deleted'));
    }

    /**
     * One of the Authorized Officer's requests: what it asks for, beside the mapping
     * as it stands. The Department Admin approves or returns it here.
     */
    public function change(Request $request, DepartmentPostChange $change): View
    {
        $this->assertChangeInScope($request, $change);
        $change->load(['department', 'post.payScale', 'requester', 'decider', 'result']);
        $payScaleId = $change->payload['pay_scale_id'] ?? null;

        return $this->page($request, 'admin.department-posts.change', [
            'change' => $change,
            'proposedPayScale' => $payScaleId === null || $payScaleId === '' ? null : PayScale::query()->find((int) $payScaleId),
            'canApprove' => $request->routeIs('department.*') && $change->isPending(),
        ]);
    }

    public function approveChange(Request $request, DepartmentPostChange $change): RedirectResponse
    {
        abort_unless($request->routeIs('department.*'), 403);
        $this->assertChangeInScope($request, $change);

        try {
            $result = $this->changes->approve($change, $this->actor($request));
        } catch (FilesystemException $e) {
            return $this->storageFailed($e);
        } catch (RuntimeException $e) {
            return back()->withErrors(['change' => $e->getMessage()]);
        }

        return ($result !== null && $result->exists
            ? redirect()->route('department.posts.show', $result)
            : redirect()->route('department.posts.index'))
            ->with('status', __('admin.department_posts.changes.approved'));
    }

    public function rejectChange(Request $request, DepartmentPostChange $change): RedirectResponse
    {
        abort_unless($request->routeIs('department.*'), 403);
        $this->assertChangeInScope($request, $change);
        $remarks = (string) $request->validate(['remarks' => ['required', 'string', 'max:1000']])['remarks'];

        try {
            $this->changes->reject($change, $this->actor($request), $remarks);
        } catch (RuntimeException $e) {
            return back()->withErrors(['change' => $e->getMessage()]);
        }

        return redirect()->route('department.posts.index')->with('status', __('admin.department_posts.changes.rejected'));
    }

    /**
     * A Government Order PDF held with a request.
     */
    public function changeDocument(Request $request, DepartmentPostChange $change, int $index): StreamedResponse
    {
        $this->assertChangeInScope($request, $change);
        $held = ($change->documents ?? [])[$index] ?? null;
        abort_if($held === null, 404);

        $disk = Storage::disk($held['disk']);
        abort_unless($disk->exists($held['path']), 404);

        return $disk->download($held['path'], $held['original_name'], ['Content-Type' => 'application/pdf']);
    }

    /**
     * Stream a Government Order.
     *
     * Streamed from the private disk rather than linked: these files sit outside
     * the public root deliberately, and the route is the only way in — which is
     * what keeps them behind the Administrator's role.
     */
    public function downloadDocument(Request $request, DepartmentPost $post, DepartmentPostOrder $order): StreamedResponse
    {
        $this->assertManages($request, $post);
        abort_unless($order->department_post_id === $post->id, 404);
        abort_unless($order->hasDocument(), 404);

        $disk = Storage::disk((string) $order->disk);

        abort_unless($disk->exists((string) $order->path), 404);

        return $disk->download((string) $order->path, (string) $order->original_name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * The Government Order could not be written to storage: nothing was saved, and
     * the form says so in words — the server path stays in the log only.
     */
    private function storageFailed(FilesystemException $e): RedirectResponse
    {
        Log::error('Department post Government Order could not be stored.', ['error' => $e::class, 'message' => $e->getMessage()]);

        return back()->withInput()->withErrors(['orders' => __('admin.department_posts.storage_failed')]);
    }

    /**
     * Record every Government Order the form carried (item 10's "Add More").
     *
     * **Rows with no number are skipped rather than refused.** The repeater renders
     * one empty row so there is always something to type into, and an Administrator
     * who adds no order at all is doing something legitimate — a mapping registered
     * without its order recorded is the state every pre-existing row is already in.
     * The request validates the rows that *do* carry a number.
     */
    private function recordOrders(DepartmentPostRequest $request, DepartmentPost $post): void
    {
        foreach ($request->orders() as $index => $order) {
            $this->mappings->recordOrder(
                $post,
                $order['government_order_number'],
                $order['government_order_date'],
                $request->file("orders.{$index}.document"),
                $request->user(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(DepartmentPost $post, ?int $scope = null): array
    {
        return [
            'post' => $post,
            // A Department Admin maps posts for their own department only.
            'departments' => Department::query()->when($scope !== null, fn ($q) => $q->whereKey($scope))->orderBy('name')->get(),
            // ...and does not choose it: it is preselected and shown, not offered (2026-10-29).
            'lockedDepartment' => $scope !== null ? Department::query()->find($scope) : null,
            'payScales' => PayScale::query()->where('status', MasterStatus::Active->value)->orderBy('label')->get(),
            'classes' => ServiceClass::cases(),
            'statuses' => MasterStatus::cases(),

            // The post name is chosen from the Designation master and never typed
            // (DPC-MODULE-FIX-001, second pass). Active rows only: a withdrawn rank
            // must not be offered to a mapping being registered today.
            'designations' => Designation::query()
                ->where('status', MasterStatus::Active->value)
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * Whose mappings this request may touch: every department's for the CGPSC
     * Administrator (null); the own department's for its Department Admin
     * (2026-10-28) and, in the officers' area, its Authorized Officer and
     * Administrative Head (2026-11-02); nobody else's.
     */
    private function scope(Request $request): ?int
    {
        $user = $request->user();

        if ($request->routeIs('post-mapping.*')) {
            abort_unless($user instanceof User && $user->department_id !== null
                && $user->hasAnyRole([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value]), 403);

            return (int) $user->department_id;
        }

        if ($user instanceof User && $user->hasRole(Role::Admin->value) && ! $request->routeIs('department.*')) {
            return null;
        }

        abort_unless($user instanceof User && app(DepartmentUserAuthority::class)->isDepartmentAdmin($user), 403);

        return (int) $user->department_id;
    }

    /**
     * The Authorized Officer's writes wait for the Department Admin (2026-11-03).
     */
    private function needsApproval(Request $request): bool
    {
        $user = $request->user();

        return $request->routeIs('post-mapping.*')
            && $user instanceof User
            && $user->hasRole(Role::AuthorizedOfficer->value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, UploadedFile|null>  $files
     */
    private function propose(Request $request, string $action, ?DepartmentPost $post, array $payload, array $files): RedirectResponse
    {
        try {
            $this->changes->propose($action, (int) $this->scope($request), $post, $payload, $files, $this->actor($request));
        } catch (FilesystemException $e) {
            return $this->storageFailed($e);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['change' => $e->getMessage()]);
        }

        return redirect()->route('post-mapping.index')->with('status', __('admin.department_posts.changes.sent'));
    }

    /**
     * The form as the request holds it: the mapping's terms and the order rows,
     * dates as typed. The PDFs travel separately.
     *
     * @return array<string, mixed>
     */
    private function formPayload(DepartmentPostRequest $request): array
    {
        $orders = [];

        foreach ($request->orders() as $index => $order) {
            $orders[$index] = [
                'government_order_number' => $order['government_order_number'],
                'government_order_date' => $order['government_order_date']->toDateString(),
            ];
        }

        return $request->safe()->except(self::ORDER_FIELDS) + ['orders' => $orders];
    }

    /**
     * @return array<string, UploadedFile|null>
     */
    private function orderFiles(DepartmentPostRequest $request): array
    {
        $files = [];

        foreach (array_keys($request->orders()) as $index) {
            $file = $request->file("orders.{$index}.document");
            $files["orders.{$index}.document"] = $file instanceof UploadedFile ? $file : null;
        }

        return $files;
    }

    private function assertChangeInScope(Request $request, DepartmentPostChange $change): void
    {
        $scope = $this->scope($request);
        abort_if($scope !== null && $change->department_id !== $scope, 403);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Who maps and supersedes a department's posts (2026-11-03): the Department
     * Admin and the CGPSC Administrator directly; the Authorized Officer by request,
     * for the Department Admin's approval. The Administrative Head reads.
     * `scope()` and the route groups have already settled who the caller is.
     */
    private function canWrite(Request $request): bool
    {
        return ! $request->routeIs('post-mapping.*') || $this->needsApproval($request);
    }

    private function assertCanWrite(Request $request): void
    {
        $this->scope($request);
        abort_unless($this->canWrite($request), 403);
    }

    private function assertManages(Request $request, DepartmentPost $post): void
    {
        $scope = $this->scope($request);
        abort_if($scope !== null && $post->department_id !== $scope, 403);
    }

    private function prefix(Request $request): string
    {
        return match (true) {
            $request->routeIs('post-mapping.*') => 'post-mapping',
            $request->routeIs('department.*') => 'department.posts',
            default => 'admin.department-posts',
        };
    }

    /**
     * The same screens under the Administration layout or the Department
     * Dashboard's, with that side's route names.
     *
     * @param  array<string, mixed>  $data
     */
    private function page(Request $request, string $view, array $data): View
    {
        $department = $request->routeIs('department.*', 'post-mapping.*');

        return view($view, $data + [
            'routePrefix' => $this->prefix($request),
            'layout' => $department ? 'layouts.app' : 'layouts.admin',
            'canWrite' => $this->canWrite($request),
        ]);
    }
}
