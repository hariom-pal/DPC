<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\PortalNotice;
use App\Domain\Master\Requests\PortalNoticeRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The public notice board (suggestion 6) — the Administrator's, like every other
 * master.
 */
class PortalNoticeController extends Controller
{
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.portal-notices.index', [
            'notices' => PortalNotice::query()->orderBy('sort_order')->orderByDesc('published_from')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.portal-notices.form', $this->formData(new PortalNotice));
    }

    public function store(PortalNoticeRequest $request): RedirectResponse
    {
        $this->crud->create(new PortalNotice, $request->validated());

        return redirect()->route('admin.portal-notices.index')
            ->with('status', __('admin.portal_notices.index.created'));
    }

    public function edit(PortalNotice $portalNotice): View
    {
        return view('admin.portal-notices.form', $this->formData($portalNotice));
    }

    public function update(PortalNoticeRequest $request, PortalNotice $portalNotice): RedirectResponse
    {
        $this->crud->update($portalNotice, $request->validated());

        return redirect()->route('admin.portal-notices.index')
            ->with('status', __('admin.portal_notices.index.updated'));
    }

    public function destroy(PortalNotice $portalNotice): RedirectResponse
    {
        $this->crud->delete($portalNotice);

        return redirect()->route('admin.portal-notices.index')
            ->with('status', __('admin.portal_notices.index.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(PortalNotice $notice): array
    {
        return ['notice' => $notice, 'statuses' => MasterStatus::cases()];
    }
}
