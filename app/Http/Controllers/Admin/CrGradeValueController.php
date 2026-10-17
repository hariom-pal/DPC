<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\CrGradeValue;
use App\Domain\Master\Requests\CrGradeValueRequest;
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
            'grades' => CrGradeValue::query()->orderByDesc('effective_from')->orderByDesc('value')->get(),
        ]);
    }

    public function store(CrGradeValueRequest $request): RedirectResponse
    {
        CrGradeValue::query()->create([...$request->validated(), 'created_by' => $request->user()?->id]);

        return redirect()->route('admin.cr-grades.index')->with('status', __('admin.cr_grades.flash.added'));
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
