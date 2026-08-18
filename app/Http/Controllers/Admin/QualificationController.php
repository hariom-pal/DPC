<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Requests\QualificationRequest;
use App\Domain\Master\Services\MasterCrudService;
use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class QualificationController extends Controller
{
    public function __construct(private readonly MasterCrudService $crud) {}

    public function index(): View
    {
        return view('admin.qualifications.index', [
            'qualifications' => Qualification::query()
                ->withCount('recruitmentRuleFeeders')
                ->orderBy('name_en')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.qualifications.form', [
            'qualification' => new Qualification,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function store(QualificationRequest $request): RedirectResponse
    {
        $this->crud->create(new Qualification, $request->validated());

        return redirect()->route('admin.qualifications.index')->with('status', 'Qualification created.');
    }

    public function edit(Qualification $qualification): View
    {
        return view('admin.qualifications.form', [
            'qualification' => $qualification,
            'statuses' => MasterStatus::cases(),
        ]);
    }

    public function update(QualificationRequest $request, Qualification $qualification): RedirectResponse
    {
        $this->crud->update($qualification, $request->validated());

        return redirect()->route('admin.qualifications.index')->with('status', 'Qualification updated.');
    }

    /**
     * Deleting a qualification that recruitment rules still require is refused.
     *
     * The rule would keep pointing at a row no screen lists, which is exactly
     * the dangling reference this master was introduced to remove. Withdrawing a
     * qualification from future use is a **status** change: Inactive keeps it on
     * the rules that already cite it and takes it out of the dropdown.
     */
    public function destroy(Qualification $qualification): RedirectResponse
    {
        $inUse = $qualification->recruitmentRuleFeeders()->count();

        if ($inUse > 0) {
            return redirect()->route('admin.qualifications.index')->with(
                'status',
                "\"{$qualification->name_en}\" is required by {$inUse} recruitment-rule feeder(s) and was not deleted. "
                .'Set its status to Inactive instead — that removes it from the dropdown without altering the rules that already cite it.',
            );
        }

        $this->crud->delete($qualification);

        return redirect()->route('admin.qualifications.index')->with('status', 'Qualification deleted.');
    }
}
