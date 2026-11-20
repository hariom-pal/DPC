@extends('layouts.portal')
@section('title', __('candidate.verification.title'))
@section('content')
    @include('candidate-verification.partials.shell-start', ['signedIn' => true])
    <h2 class="h6 mb-3">{{ __('candidate.verification.dashboard.heading') }}</h2>
    @forelse ($participants as $participant)
        @php
            $row = $participant->row;
            $cycle = $participant->cycle;
        @endphp
        <div class="gov-card p-3 mb-3" data-participant="{{ $participant->uuid }}">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <div class="fw-semibold">{{ $row->name }} · {{ $row->employee_number ?? '—' }}</div>
                    <div class="small text-body-secondary">{{ $row->proposal->department?->name }} · {{ $row->promotion_from_post ?? '—' }}</div>
                    <div class="small">{{ __('candidate.verification.dashboard.reference') }}: {{ \App\Domain\CandidateVerification\Services\CandidateRecordView::reference($row->proposal) }} → {{ $row->proposal->promotionToPost?->name }}</div>
                </div>
                <div class="text-end small">
                    <div>{{ __('candidate.verification.dashboard.started') }}: {{ $cycle->approved_at->format('d-m-Y') }}</div>
                    <div>{{ __('candidate.verification.dashboard.deadline') }}: <strong>{{ $cycle->deadline_at->format('d-m-Y') }}</strong></div>
                    <div>{{ $cycle->acceptsSubmissions() ? __('candidate.verification.dashboard.days_left', ['days' => $cycle->daysLeft()]) : __('candidate.verification.dashboard.closed') }}</div>
                    <span class="badge text-bg-secondary" data-status="{{ $participant->status->value }}">{{ $participant->status->label() }}</span>
                </div>
            </div>
            @include('candidate-verification.partials.progress', ['decisions' => $decisions[$participant->id] ?? []])
            <a class="btn btn-primary btn-sm" href="{{ route('candidate-verification.section', [$participant->uuid, 'seniority']) }}">{{ __('candidate.verification.dashboard.open') }}</a>
        </div>
    @empty
        <div class="gov-card p-3">{{ __('candidate.verification.dashboard.none') }}</div>
    @endforelse
    @include('candidate-verification.partials.shell-end')
@endsection
