{{-- MoM of 16 November 2026, point 3: a committee member's return — the sections to
     correct, each with its remark — and the AO's resubmission straight to the
     committee. Expects: $proposal. --}}
@if ($proposal->committee_returned_at)
    <div class="alert alert-warning" id="committee-return">
        <div class="fw-semibold">{{ __('dpc.committee_decision.returned_banner', ['member' => $proposal->committee_return['member'] ?? '—']) }}</div>
        <ul class="mb-1 small">
            @foreach ((array) ($proposal->committee_return['sections'] ?? []) as $section => $remark)
                <li data-returned-section="{{ $section }}"><strong>{{ __('dpc.committee_decision.sections.'.$section) }}:</strong> {{ $remark }}</li>
            @endforeach
        </ul>
        <div class="small mb-2">{{ __('dpc.committee_decision.others_locked') }}</div>
        @error('committee_resubmit')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
        @can('committeeResubmit', $proposal)
            <a href="{{ route('dpc-proposals.employees.index', $proposal) }}" class="btn btn-outline-primary btn-sm">{{ __('dpc.committee_decision.correct') }}</a>
            <form method="POST" action="{{ route('dpc-proposals.committee-resubmit', $proposal) }}" class="d-inline" data-confirm="{{ __('dpc.committee_decision.resubmit_confirm') }}">
                @csrf
                <button class="btn btn-success btn-sm" id="committee-resubmit">{{ __('dpc.committee_decision.resubmit') }}</button>
            </form>
        @endcan
    </div>
@endif
