<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The days each stage of a DPC is allowed (MoM of 16 November 2026, point 6), set
 * by the CGPSC Administrator. A stage left blank has no deadline.
 */
class StageTimelineController extends Controller
{
    public function index(): View
    {
        $timelines = WorkflowStageTimeline::query()->get()->keyBy(fn (WorkflowStageTimeline $timeline): string => $timeline->stage->value);

        return view('admin.stage-timelines.index', [
            'stages' => DpcStage::cases(),
            'timelines' => $timelines,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (DpcStage::cases() as $stage) {
            $rules['days.'.$stage->value] = ['nullable', 'integer', 'min:1', 'max:365'];
        }
        $data = $request->validate($rules);

        foreach (DpcStage::cases() as $stage) {
            $days = $data['days'][$stage->value] ?? null;

            WorkflowStageTimeline::query()->updateOrCreate(
                ['stage' => $stage->value],
                ['days' => $days === null || $days === '' ? null : (int) $days, 'updated_by' => $request->user()?->id],
            );
        }

        return redirect()->route('admin.stage-timelines.index')->with('status', __('admin.stage_timelines.saved'));
    }
}
