{{-- The next step (MoM of 12 November 2026, point 1): a green banner saying what comes
     next and for whom; the element it names (`data-next-step-target`) is flagged in
     green; and, right after an action, the same sentence in a pop-up. A step flashed
     as `next_step` is shown by the layout instead.
     Expects: $nextStep (array{text, target}|null). --}}
@if ($nextStep !== null)
    <div class="alert alert-success d-flex gap-2 align-items-center next-step-banner" id="next-step" role="status"
         @if ($nextStep['target']) data-next-step-target="{{ $nextStep['target'] }}" @endif>
        <span class="badge text-bg-success">{{ __('ui.next_step.badge') }}</span>
        <div>{{ $nextStep['text'] }}</div>
    </div>
    @if (session('status') && ! session('next_step'))
        @include('partials.next-step-modal', ['message' => session('status'), 'next' => $nextStep['text']])
    @endif
@endif
