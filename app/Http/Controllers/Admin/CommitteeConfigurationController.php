<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

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
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.committee-configurations.index', [
            'configurations' => CommitteeConfiguration::query()->with('committeeType')->latest('id')->paginate(20),
        ]);
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
