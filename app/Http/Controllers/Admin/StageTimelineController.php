<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Dpc\Enums\DpcStage;
use App\Domain\Dpc\Models\WorkflowStageTimeline;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The days each stage of a DPC is allowed (MoM of 16 November 2026, point 6), set
 * by the CGPSC Administrator. A stage left blank has no deadline.
 */
class StageTimelineController extends Controller
{
    public function index(): View
    {
        return view('admin.stage-timelines.index', [
            'stages' => DpcStage::cases(),
            'timelines' => collect(DpcStage::cases())->mapWithKeys(fn (DpcStage $stage): array => [$stage->value => WorkflowStageTimeline::for($stage)]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (DpcStage::cases() as $stage) {
            $rules['days.'.$stage->value] = ['nullable', 'integer', 'min:1', 'max:365'];
            $rules['on_expiry.'.$stage->value] = ['nullable', Rule::in($stage->expiryActions())];
            $rules['every.'.$stage->value] = ['nullable', 'integer', 'min:1', 'max:30'];
            $rules['email.'.$stage->value] = ['nullable', 'boolean'];
            $rules['sms.'.$stage->value] = ['nullable', 'boolean'];
        }
        $data = $request->validate($rules);

        foreach (DpcStage::cases() as $stage) {
            $current = WorkflowStageTimeline::for($stage);
            $days = $data['days'][$stage->value] ?? null;

            WorkflowStageTimeline::query()->updateOrCreate(['stage' => $stage->value], [
                'days' => $days === null || $days === '' ? null : (int) $days,
                // A field not posted keeps what it was.
                'on_expiry' => $data['on_expiry'][$stage->value] ?? $current->on_expiry,
                'reminder_every_days' => (int) ($data['every'][$stage->value] ?? $current->reminder_every_days),
                'remind_email' => $request->has('email') ? (bool) ($data['email'][$stage->value] ?? false) : $current->remind_email,
                'remind_sms' => $request->has('sms') ? (bool) ($data['sms'][$stage->value] ?? false) : $current->remind_sms,
                'updated_by' => $request->user()?->id,
            ]);
        }

        return redirect()->route('admin.stage-timelines.index')->with('status', __('admin.stage_timelines.saved'));
    }
}
