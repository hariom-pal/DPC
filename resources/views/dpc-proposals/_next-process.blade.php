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
@if ($proposal->secretary_escalated_at)
    <div class="alert alert-warning py-2 small" id="secretary-escalated">{{ __('dpc.stage.escalated', ['date' => $proposal->secretary_escalated_at->format('d-m-Y')]) }}</div>
@endif
