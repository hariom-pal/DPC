<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Master\Models\ClassMaster;
use App\Domain\Master\Requests\RecordPromotionCrRuleRequest;
use App\Domain\Master\Services\PromotionCrRules;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Class Master → a class → its promotion rules: for promotion into the class from the
 * same class or the one below, how many years of CR, Loyalty and Property Declaration
 * a DPC needs, and each year's requirement.
 */
class PromotionCrRuleController extends Controller
{
    public function __construct(private readonly PromotionCrRules $rules) {}

    public function index(ClassMaster $classMaster): View
    {
        return view('admin.promotion-rules.index', [
            'class' => $classMaster,
            'fromClasses' => $this->rules->fromClasses($classMaster),
            'history' => $this->rules->historyInto($classMaster),
            'year' => (int) now()->year,
        ]);
    }

    public function create(Request $request, ClassMaster $classMaster): View
    {
        $fromClasses = $this->rules->fromClasses($classMaster);
        $from = (string) $request->query('from', $fromClasses[0]->code);
        $year = (int) old('effective_from_year', $request->query('year', (string) (now()->year + 1)));

        return view('admin.promotion-rules.form', [
            'class' => $classMaster,
            'fromClasses' => $fromClasses,
            'from' => $from,
            'year' => $year,
            // The form starts from the rule in force, so a change is typed once.
            'current' => $this->rules->ruleFor($classMaster->code, $from, $year),
            'grades' => $this->rules->gradesForYear($year),
            'maxYears' => RecordPromotionCrRuleRequest::MAX_YEARS,
        ]);
    }

    public function store(RecordPromotionCrRuleRequest $request, ClassMaster $classMaster): RedirectResponse
    {
        $rule = $this->rules->record($classMaster, $request->validated(), $request->user());

        return redirect()->route('admin.service-classes.promotion-rules.index', $classMaster)
            ->with('status', __('admin.promotion_rules.flash.recorded', [
                'from' => $rule->from_class,
                'to' => $rule->to_class,
                'year' => $rule->effective_from_year,
            ]));
    }
}
