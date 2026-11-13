{{-- The AO marks the DPC Proceeding Complete (MoM of 12 November 2026, point 4): once
     the promotion orders are issued, and for good. Expects: $proposal. --}}
@php
    $completion = app(\App\Domain\Dpc\Services\DpcCompletionService::class);
    $completionRefusal = $completion->refusal($proposal, auth()->user());
@endphp
@if ($proposal->proceeding_completed_at !== null)
    <div class="alert alert-danger d-flex gap-2 align-items-start" id="dpc-completed">
        <span class="badge text-bg-danger flex-shrink-0">{{ __('dpc.phase.closed') }}</span>
        <div>{{ __('dpc.completion.completed_on', [
            'date' => $proposal->proceeding_completed_at->format('d-m-Y H:i'),
            'name' => \App\Domain\Authentication\Models\User::query()->find($proposal->proceeding_completed_by)?->name ?? '—',
        ]) }}</div>
    </div>
@elseif ($completionRefusal === null)
    <div class="card border-success shadow-sm mb-3" id="dpc-complete">
        <div class="card-body">
            <h2 class="h6 mb-1">{{ __('dpc.completion.heading') }}</h2>
            <p class="small text-muted mb-2">{{ __('dpc.completion.lead') }}</p>
            @error('complete')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
            <form method="POST" action="{{ route('dpc-proposals.complete', $proposal) }}" data-confirm="{{ __('dpc.completion.confirm') }}">
                @csrf
                <div class="form-check mb-2">
                    <input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox" name="confirm" value="1" id="completion-confirm" required>
                    <label class="form-check-label small" for="completion-confirm">{{ __('dpc.completion.declaration') }}</label>
                    @error('confirm')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-danger btn-sm">{{ __('dpc.completion.button') }}</button>
            </form>
        </div>
    </div>
@endif
