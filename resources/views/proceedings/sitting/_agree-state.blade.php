{{-- This member's own Agree or Disagree on one candidate, and the Disagree form while
     it is open (2026-11-25). Expects: $proposal, $row, $review (theirs or null), $compact. --}}
@php($disagreeOpen = $review !== null && $review->disagreed_at === null && app(\App\Domain\Proceeding\Services\SittingMeetingService::class)->okRefusal($proposal) === null)
@if ($review === null)
    <span class="badge text-bg-light border" data-my-state="pending">{{ __('proceeding.sitting.ok_pending') }}</span>
@elseif ($review->disagreed_at !== null)
    <span class="badge text-bg-danger" data-my-state="disagreed">{{ __('proceeding.sitting.disagreed_yours') }}</span>
    <div class="small text-break mt-1" style="white-space: pre-line">{{ $review->disagree_remarks }}</div>
@else
    <span class="badge text-bg-success" data-my-state="agreed">{{ __('proceeding.sitting.agreed_short') }}</span>
@endif
@if ($disagreeOpen)
    <details class="mt-1" @if (! $compact) open @endif>
        <summary class="small text-danger" data-disagree-toggle="{{ $row->id }}">{{ __('proceeding.sitting.disagree') }}</summary>
        <form method="POST" action="{{ route('sitting.candidate.disagree', [$proposal, $row]) }}" class="mt-1" data-disagree-form="{{ $row->id }}"
              onsubmit="return confirm(@js(__('proceeding.sitting.disagree_confirm')))">
            @csrf
            <label class="form-label small mb-1" for="disagree-{{ $row->id }}">{{ __('proceeding.sitting.disagree_remark') }}</label>
            <textarea class="form-control form-control-sm" id="disagree-{{ $row->id }}" name="remarks" rows="3" required></textarea>
            <button class="btn btn-danger btn-sm mt-1">{{ __('proceeding.sitting.disagree_submit') }}</button>
        </form>
    </details>
@endif
