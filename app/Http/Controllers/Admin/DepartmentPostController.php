<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DepartmentPostOrder;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Requests\DepartmentPostRequest;
use App\Domain\Master\Requests\SupersedeDepartmentPostRequest;
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
use Illuminate\Support\Facades\Storage;
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
    ) {}

    public function index(Request $request): View
    {
        $scope = $this->scope($request);

        return $this->page($request, 'admin.department-posts.index', [
            // Mappings in force first, then the superseded ones beneath them, so
            // the screen reads as "what applies now, and what applied before".
            'posts' => $this->searched($request, DepartmentPost::query()
                ->with(['department', 'payScale', 'orders'])
                ->when($scope !== null, fn ($q) => $q->where('department_id', $scope))
                ->orderByRaw('active_flag IS NULL')
                ->orderBy('name')
                ->orderByDesc('effective_from'), 'department-posts')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->page($request, 'admin.department-posts.form', $this->formData(new DepartmentPost, $this->scope($request)));
    }

    public function store(DepartmentPostRequest $request): RedirectResponse
    {
        $scope = $this->scope($request);
        abort_if($scope !== null && $request->integer('department_id') !== $scope, 403);

        $post = $this->crud->create(new DepartmentPost, $request->safe()->except(self::ORDER_FIELDS));

        $this->recordOrders($request, $post);

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
        $this->assertManages($request, $post);

        return $this->page($request, 'admin.department-posts.form', $this->formData($post, $this->scope($request)));
    }

    public function update(DepartmentPostRequest $request, DepartmentPost $post): RedirectResponse
    {
        $this->assertManages($request, $post);
        $scope = $this->scope($request);
        abort_if($scope !== null && $request->integer('department_id') !== $scope, 403);

        $this->crud->update($post, $request->safe()->except(self::ORDER_FIELDS));

        $this->recordOrders($request, $post);

        return redirect()->route($this->prefix($request).'.show', $post)
            ->with('status', __('admin.department_posts.updated'));
    }

    /**
     * A new Government Order for the same post: close this mapping, open its
     * successor.
     */
    public function supersedeForm(Request $request, DepartmentPost $post): View
    {
        $this->assertManages($request, $post);

        return $this->page($request, 'admin.department-posts.supersede', $this->formData($post, $this->scope($request)));
    }

    public function supersede(SupersedeDepartmentPostRequest $request, DepartmentPost $post): RedirectResponse
    {
        $this->assertManages($request, $post);

        try {
            $replacement = $this->mappings->supersede(
                $post,
                $request->safe()->except('government_order_document'),
                $request->user(),
                $request->file('government_order_document'),
            );
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
        $this->assertManages($request, $post);
        $this->crud->delete($post);

        return redirect()->route($this->prefix($request).'.index')
            ->with('status', __('admin.department_posts.deleted'));
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
     * Whose mappings this request may touch (2026-10-28): every department's for
     * the CGPSC Administrator (null), the own department's for its Department
     * Admin, and nobody else's.
     */
    private function scope(Request $request): ?int
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasRole(Role::Admin->value) && ! $request->routeIs('department.*')) {
            return null;
        }

        abort_unless($user instanceof User && app(DepartmentUserAuthority::class)->isDepartmentAdmin($user), 403);

        return (int) $user->department_id;
    }

    private function assertManages(Request $request, DepartmentPost $post): void
    {
        $scope = $this->scope($request);
        abort_if($scope !== null && $post->department_id !== $scope, 403);
    }

    private function prefix(Request $request): string
    {
        return $request->routeIs('department.*') ? 'department.posts' : 'admin.department-posts';
    }

    /**
     * The same screens under the Administration layout or the Department
     * Dashboard's, with that side's route names.
     *
     * @param  array<string, mixed>  $data
     */
    private function page(Request $request, string $view, array $data): View
    {
        $department = $request->routeIs('department.*');

        return view($view, $data + [
            'routePrefix' => $this->prefix($request),
            'layout' => $department ? 'layouts.app' : 'layouts.admin',
        ]);
    }
}
