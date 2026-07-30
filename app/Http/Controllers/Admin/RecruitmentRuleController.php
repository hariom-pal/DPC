<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Requests\RecruitmentRuleRequest;
use App\Domain\Master\Services\RecruitmentRuleService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecruitmentRuleController extends Controller
{
    private const RULE_FIELDS = [
        'cadre_id', 'government_order_number', 'direct_recruitment_pct',
        'promotion_recruitment_pct', 'effective_from', 'effective_to', 'status',
    ];

    public function __construct(private readonly RecruitmentRuleService $service) {}

    public function index(): View
    {
        return view('admin.recruitment-rules.index', [
            'rules' => RecruitmentRule::query()->with('cadre')->orderByDesc('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.recruitment-rules.form', $this->formData(new RecruitmentRule));
    }

    public function store(RecruitmentRuleRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $this->service->create(
            Arr::only($validated, self::RULE_FIELDS),
            $validated['feeders'] ?? [],
            $request->file('go_document'),
        );

        return redirect()->route('admin.recruitment-rules.index')->with('status', 'Recruitment rule created.');
    }

    public function edit(RecruitmentRule $recruitmentRule): View|RedirectResponse
    {
        if (! $recruitmentRule->isEditable()) {
            return redirect()->route('admin.recruitment-rules.index')
                ->with('status', 'Only draft rules can be edited. Create a new version to amend an active rule.');
        }

        $recruitmentRule->load('feeders');

        return view('admin.recruitment-rules.form', $this->formData($recruitmentRule));
    }

    public function update(RecruitmentRuleRequest $request, RecruitmentRule $recruitmentRule): RedirectResponse
    {
        if (! $recruitmentRule->isEditable()) {
            return redirect()->route('admin.recruitment-rules.index')
                ->with('status', 'Only draft rules can be edited. Create a new version instead.');
        }

        $validated = $request->validated();
        $this->service->updateDraft(
            $recruitmentRule,
            Arr::only($validated, self::RULE_FIELDS),
            $validated['feeders'] ?? [],
            $request->file('go_document'),
        );

        return redirect()->route('admin.recruitment-rules.index')->with('status', 'Recruitment rule updated.');
    }

    public function newVersion(RecruitmentRule $recruitmentRule): RedirectResponse
    {
        $version = $this->service->newVersion($recruitmentRule);

        return redirect()->route('admin.recruitment-rules.edit', $version)
            ->with('status', "New draft version v{$version->version} created.");
    }

    public function destroy(RecruitmentRule $recruitmentRule): RedirectResponse
    {
        $this->service->delete($recruitmentRule);

        return redirect()->route('admin.recruitment-rules.index')->with('status', 'Recruitment rule deleted.');
    }

    public function download(RecruitmentRule $recruitmentRule): StreamedResponse
    {
        abort_if($recruitmentRule->go_document_path === null, 404);

        return Storage::disk((string) $recruitmentRule->go_document_disk)
            ->download($recruitmentRule->go_document_path, 'GO-'.$recruitmentRule->government_order_number.'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(RecruitmentRule $rule): array
    {
        return [
            'rule' => $rule,
            'cadres' => Cadre::query()->where('status', MasterStatus::Active->value)->orderBy('name')->get(),
            'statuses' => MasterStatus::cases(),
        ];
    }
}
