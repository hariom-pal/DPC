@extends('layouts.portal')
@section('title', __('candidate.verification.title'))
@section('content')
    @include('candidate-verification.partials.shell-start', ['signedIn' => true])
    <h2 class="h6 mb-3">{{ __('candidate.verification.dashboard.heading') }}</h2>
    @php
        // Two groups (2026-11-20): the DPCs whose verification is open now, and every
        // other DPC of the candidate's — under review, with the Commission or concluded — read only.
        [$openRows, $otherRows] = $rows->partition(fn ($row) => $participants->get($row->id)?->cycle?->acceptsSubmissions() === true);
    @endphp
    @if ($rows->isEmpty())
        <div class="gov-card p-3">{{ __('candidate.verification.dashboard.none') }}</div>
    @endif
    @foreach ([['open', $openRows], ['other', $otherRows]] as [$group, $groupRows])
    @if ($groupRows->isNotEmpty())
    <h3 class="h6 text-body-secondary mt-3 mb-2" id="dpc-group-{{ $group }}">{{ __('candidate.verification.dashboard.group_'.$group) }} ({{ $groupRows->count() }})</h3>
    @endif
    @foreach ($groupRows as $row)
        @php
            $participant = $participants->get($row->id);
            $cycle = $participant?->cycle;
            $actionable = \App\Domain\CandidateVerification\Services\CandidateOutcome::actionable($participant);
            $result = $results[$row->id] ?? null;
        @endphp
        <div class="gov-card p-3 mb-3" data-row="{{ $row->id }}" @if ($participant) data-participant="{{ $participant->uuid }}" @endif>
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <div class="fw-semibold">{{ $row->name }} · {{ $row->employee_number ?? '—' }}</div>
                    <div class="small text-body-secondary">{{ $row->proposal->department?->name }} · {{ $row->promotion_from_post ?? '—' }}</div>
                    <div class="small">{{ __('candidate.verification.dashboard.reference') }}: {{ \App\Domain\CandidateVerification\Services\CandidateRecordView::reference($row->proposal) }} → {{ $row->proposal->promotionToPost?->name }}</div>
                    <div class="small" data-stage>{{ __('candidate.verification.dashboard.stage') }}: {{ $stages[$row->id] ?? '—' }}</div>
                    <div class="small" data-result>
                        {{ __('candidate.verification.dashboard.result') }}:
                        @if ($result === null)
                            <span class="text-body-secondary">{{ __('candidate.verification.dashboard.result_pending') }}</span>
                        @else
                            <strong>{{ $result['promoted'] ? __('candidate.verification.dashboard.promoted') : __('candidate.verification.dashboard.not_promoted') }}</strong>
                            <span class="text-body-secondary">({{ $result['declared_at']->format('d-m-Y') }})</span>
                        @endif
                    </div>
                </div>
                @if ($participant)
                    <div class="text-end small">
                        <div>{{ __('candidate.verification.dashboard.started') }}: {{ $cycle->approved_at->format('d-m-Y') }}</div>
                        <div>{{ __('candidate.verification.dashboard.deadline') }}: <strong>{{ $cycle->deadline_at->format('d-m-Y') }}</strong></div>
                        <div>{{ $cycle->acceptsSubmissions() ? __('candidate.verification.dashboard.days_left', ['days' => $cycle->daysLeft()]) : __('candidate.verification.dashboard.closed') }}</div>
                        <span class="badge text-bg-secondary" data-status="{{ $participant->status->value }}">{{ $participant->status->label() }}</span>
                    </div>
                @endif
            </div>
            @if ($participant)
                @include('candidate-verification.partials.progress', ['decisions' => $decisions[$participant->id] ?? []])
                <a class="btn btn-sm {{ $actionable ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('candidate-verification.section', [$participant->uuid, 'seniority']) }}">
                    {{ $actionable ? __('candidate.verification.dashboard.open') : __('candidate.verification.dashboard.view') }}
                </a>
            @else
                <a class="btn btn-sm btn-outline-primary mt-2" href="{{ route('candidate-verification.record', [$row->id, 'seniority']) }}">{{ __('candidate.verification.dashboard.view') }}</a>
                <div class="small text-body-secondary mt-1">{{ __('candidate.verification.dashboard.read_only') }}</div>
            @endif
        </div>
    @endforeach
    @endforeach
    @include('candidate-verification.partials.shell-end')
@endsection
