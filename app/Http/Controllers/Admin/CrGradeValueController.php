<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\CrGradeValue;
use App\Domain\Master\Requests\CrGradeValueRequest;
use App\Domain\Master\Requests\DeactivateCrGradeValueRequest;
use App\Domain\Master\Requests\UpdateCrGradeRemarksRequest;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Eligible CR Grades: the numeric values a CR may carry, each valid from a date. A
 * value is added, never edited; one added by mistake may be removed before its date.
 */
class CrGradeValueController extends Controller
{
    public function index(): View
    {
        return view('admin.cr-grades.index', [
            'grades' => CrGradeValue::query()->orderByDesc('value')->orderByDesc('effective_from')->get(),
            'deactivators' => User::query()
                ->whereIn('id', CrGradeValue::query()->whereNotNull('deactivated_by')->pluck('deactivated_by'))
                ->pluck('name', 'id'),
            'today' => today(),
        ]);
    }

    public function store(CrGradeValueRequest $request): RedirectResponse
    {
        CrGradeValue::query()->create([...$request->validated(), 'created_by' => $request->user()?->id]);

        return redirect()->route('admin.cr-grades.index')->with('status', __('admin.cr_grades.flash.added'));
    }

    /**
     * Change a grade's remarks. Value and window stay; the audit trail records the
     * previous wording.
     */
    public function updateRemarks(UpdateCrGradeRemarksRequest $request, CrGradeValue $crGrade): RedirectResponse
    {
        $remarks = trim((string) $request->validated('remarks'));

        $crGrade->forceFill(['remarks' => $remarks === '' ? null : $remarks])->save();

        return redirect()->route('admin.cr-grades.index')->with('status', __('admin.cr_grades.flash.remarks_updated', ['value' => $crGrade->value]));
    }

    /**
     * End a grade's window. The row stays, with who ended it, when and why.
     */
    public function deactivate(DeactivateCrGradeValueRequest $request, CrGradeValue $crGrade): RedirectResponse
    {
        $crGrade->forceFill([
            'effective_to' => $request->validated('effective_to'),
            'deactivation_reason' => $request->validated('deactivation_reason'),
            'deactivated_by' => $request->user()?->id,
            'deactivated_at' => now(),
        ])->save();

        return redirect()->route('admin.cr-grades.index')->with('status', __('admin.cr_grades.flash.deactivated', [
            'value' => $crGrade->value,
            'date' => $crGrade->effective_to?->format('d-m-Y'),
        ]));
    }

    public function destroy(CrGradeValue $crGrade): RedirectResponse
    {
        if ($crGrade->effective_from->lte(today())) {
            return redirect()->route('admin.cr-grades.index')
                ->withErrors(['value' => __('admin.cr_grades.errors.already_in_force')]);
        }

        $crGrade->delete();

        return redirect()->route('admin.cr-grades.index')->with('status', __('admin.cr_grades.flash.removed'));
    }
}
