{{-- MoM of 16 November 2026, point 5: where the DPC stands, what happens next and
     to whom it goes, with this user's next task when the move is theirs. Expects:
     $proposal. --}}
@php
    $stageService = app(\App\Domain\Dpc\Services\DpcStageService::class);
    $deadline = $stageService->deadline($proposal);
@endphp
@if ($deadline !== null)
    <div class="alert {{ $deadline['overdue'] ? 'alert-danger' : 'alert-primary' }} py-2" id="next-process" data-stage="{{ $deadline['stage']->value }}">
        <div class="fw-semibold">{{ __('dpc.stage.next_heading') }}</div>
        <div class="small">{{ __('dpc.stage.now', ['stage' => $deadline['stage']->label(), 'action' => $deadline['stage']->action()]) }}</div>
        <ul class="small mb-1" data-pending-items>
            @foreach ($stageService->pendingItems($proposal, $deadline['stage']) as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
        <div class="small" data-next-stage="{{ $deadline['stage']->next()?->value }}">
            {{ $deadline['stage']->next() ? __('dpc.stage.then', ['next' => $deadline['stage']->next()->label()]) : __('dpc.stage.last') }}
        </div>
        <div class="small">
            @if ($deadline['due_at'] === null)
                {{ __('dpc.stage.no_deadline') }}
            @elseif ($deadline['overdue'])
                <strong>{{ __('dpc.stage.overdue', ['date' => $deadline['due_at']->format('d-m-Y')]) }}</strong>
            @else
                {{ __('dpc.stage.due', ['date' => $deadline['due_at']->format('d-m-Y')]) }}
            @endif
        </div>
        @if (in_array((int) auth()->id(), $stageService->actorIds($proposal, $deadline['stage']), true))
            <a href="{{ $stageService->linkFor(auth()->user(), $proposal, $deadline['stage']) }}" class="alert-link small" id="next-process-task">{{ __('dpc.stage.your_action') }}</a>
        @endif
    </div>
@endif
{{-- Every automatic action on this DPC (2026-11-17): forwarded or cancelled once a deadline passed. --}}
@php
    $automatic = \App\Domain\Shared\Workflow\WorkflowTransition::query()
        ->where('subject_type', $proposal->getMorphClass())->where('subject_id', $proposal->id)
        ->whereNull('actor_id')->whereIn('event', ['secretary_escalated', 'ah_auto_forward', 'auto_cancel'])
        ->orderBy('occurred_at')->get();
@endphp
@if ($automatic->isNotEmpty())
    <div class="alert alert-warning py-2 small" id="secretary-escalated">
        @foreach ($automatic as $transition)
            <div data-automatic-action="{{ $transition->event }}">{{ $transition->occurred_at->format('d-m-Y') }}: {{ $transition->remarks }}</div>
        @endforeach
    </div>
@endif
