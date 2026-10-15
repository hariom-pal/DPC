<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DepartmentPostOrder;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Requests\DepartmentPostRequest;
use App\Domain\Master\Requests\SupersedeDepartmentPostRequest;
use App\Domain\Master\Services\DepartmentPostMappingService;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
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
        return view('admin.department-posts.index', [
            // Mappings in force first, then the superseded ones beneath them, so
            // the screen reads as "what applies now, and what applied before".
            'posts' => $this->searched($request, DepartmentPost::query()
                ->with(['department', 'payScale', 'orders'])
                ->orderByRaw('active_flag IS NULL')
                ->orderBy('name')
                ->orderByDesc('effective_from'), 'department-posts')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('admin.department-posts.form', $this->formData(new DepartmentPost));
    }

    public function store(DepartmentPostRequest $request): RedirectResponse
    {
        $post = $this->crud->create(new DepartmentPost, $request->safe()->except(self::ORDER_FIELDS));

        $this->recordOrders($request, $post);

        return redirect()->route('admin.department-posts.show', $post)
            ->with('status', __('admin.department_posts.created'));
    }

    /**
     * The mapping in full — its terms, and every Government Order that bears on
     * it (change register of 2026-08-26, item 10).
     */
    public function show(DepartmentPost $post): View
    {
        $post->load(['department', 'payScale', 'orders.uploader']);

        return view('admin.department-posts.show', [
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

    public function edit(DepartmentPost $post): View
    {
        return view('admin.department-posts.form', $this->formData($post));
    }

    public function update(DepartmentPostRequest $request, DepartmentPost $post): RedirectResponse
    {
        $this->crud->update($post, $request->safe()->except(self::ORDER_FIELDS));

        $this->recordOrders($request, $post);

        return redirect()->route('admin.department-posts.show', $post)
            ->with('status', __('admin.department_posts.updated'));
    }

    /**
     * A new Government Order for the same post: close this mapping, open its
     * successor.
     */
    public function supersedeForm(DepartmentPost $post): View
    {
        return view('admin.department-posts.supersede', $this->formData($post));
    }

    public function supersede(SupersedeDepartmentPostRequest $request, DepartmentPost $post): RedirectResponse
    {
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

        return redirect()->route('admin.department-posts.show', $replacement)
            ->with('status', __('admin.department_posts.superseded'));
    }

    public function destroy(DepartmentPost $post): RedirectResponse
    {
        $this->crud->delete($post);

        return redirect()->route('admin.department-posts.index')
            ->with('status', __('admin.department_posts.deleted'));
    }

    /**
     * Stream a Government Order.
     *
     * Streamed from the private disk rather than linked: these files sit outside
     * the public root deliberately, and the route is the only way in — which is
     * what keeps them behind the Administrator's role.
     */
    public function downloadDocument(DepartmentPost $post, DepartmentPostOrder $order): StreamedResponse
    {
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
    private function formData(DepartmentPost $post): array
    {
        return [
            'post' => $post,
            'departments' => Department::query()->orderBy('name')->get(),
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
}
