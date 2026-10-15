<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Committee\Services\CommitteeBoundsReconciler;
use App\Domain\Master\Models\CommitteeConfiguration;
use App\Domain\Master\Models\CommitteeType;
use App\Domain\Master\Requests\CommitteeConfigurationRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class CommitteeConfigurationController extends Controller
{
    public function __construct(
        private readonly MasterCrudService $crud,
        private readonly CommitteeBoundsReconciler $bounds,
    ) {}

    public function index(): View
    {
        $configurations = CommitteeConfiguration::query()->with('committeeType')->latest('id')->paginate(20);

        return view('admin.committee-configurations.index', [
            'configurations' => $configurations,

            /*
             | Which configurations still hold bounds from before change item 15.
             |
             | Reported rather than corrected: an installation seeded before
             | 2026-08-24 holds 1–5 where the register says 3–6, and no deployment
             | can tell that apart from a bound an Administrator chose. Keyed by id
             | so the row can show its own numbers beside the register's.
             */
            'drift' => $configurations->getCollection()
                ->mapWithKeys(fn (CommitteeConfiguration $c): array => [$c->id => $this->bounds->drift($c)])
                ->filter()
                ->all(),
        ]);
    }

    /**
     * Apply change item 15's bounds to this configuration, on the Administrator's
     * instruction.
     */
    public function alignBounds(CommitteeConfiguration $committeeConfiguration): RedirectResponse
    {
        $this->bounds->apply($committeeConfiguration);

        return redirect()
            ->route('admin.committee-configurations.index')
            ->with('status', __('admin.committee_configurations.index.bounds_aligned'));
    }

    public function create(): View
    {
        return view('admin.committee-configurations.form', $this->formData(new CommitteeConfiguration));
    }

    public function store(CommitteeConfigurationRequest $request): RedirectResponse
    {
        $this->crud->create(new CommitteeConfiguration, $request->validated());

        return redirect()->route('admin.committee-configurations.index')->with('status', 'Committee configuration created.');
    }

    public function edit(CommitteeConfiguration $committeeConfiguration): View
    {
        return view('admin.committee-configurations.form', $this->formData($committeeConfiguration));
    }

    public function update(CommitteeConfigurationRequest $request, CommitteeConfiguration $committeeConfiguration): RedirectResponse
    {
        $this->crud->update($committeeConfiguration, $request->validated());

        return redirect()->route('admin.committee-configurations.index')->with('status', 'Committee configuration updated.');
    }

    public function destroy(CommitteeConfiguration $committeeConfiguration): RedirectResponse
    {
        $this->crud->delete($committeeConfiguration);

        return redirect()->route('admin.committee-configurations.index')->with('status', 'Committee configuration deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(CommitteeConfiguration $configuration): array
    {
        return [
            'configuration' => $configuration,
            'committeeTypes' => CommitteeType::query()->where('status', MasterStatus::Active->value)->orderBy('code')->get(),
            'statuses' => MasterStatus::cases(),
        ];
    }
}
