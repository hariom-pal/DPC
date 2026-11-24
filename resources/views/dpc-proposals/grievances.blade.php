@extends('layouts.app')
@section('title', __('candidate.grievance.review.heading'))
@section('content')
    {{-- The candidates' grievances (2026-11-19): decided one by one by the AH. --}}
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ __('candidate.grievance.review.heading') }}</h1>
            <p class="text-muted mb-0">{{ $proposal->citation() }} · <span class="badge {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span></p>
        </div>
        <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary btn-sm">{{ __('candidate.verification.actions.back') }}</a>
    </div>
    @include('partials.alerts')
    @if ($errors->any())
        <div class="alert alert-danger py-2 small">@foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach</div>
    @endif

    @if ($cycle === null)
        <div class="card border-0 shadow-sm"><div class="card-body text-muted">{{ __('candidate.grievance.review.none') }}</div></div>
    @else
        <div class="card border-0 shadow-sm mb-3"><div class="card-body small" id="verification-summary">
            <div>{{ __('candidate.verification.dashboard.deadline') }}: {{ $cycle->deadline_at->format('d-m-Y H:i') }} ({{ $cycle->duration_days }})</div>
            <div>{{ __('candidate.grievance.review.summary', ['submitted' => $counts['submitted'], 'total' => $cycle->participant_count, 'grievances' => $counts['grievances'], 'no_response' => $counts['no_response']]) }}</div>
            <div>{{ __('candidate.grievance.review.notifications', ['sent' => $notifications['sent'] ?? 0, 'queued' => $notifications['queued'] ?? 0, 'failed' => $notifications['failed'] ?? 0, 'skipped' => $notifications['skipped'] ?? 0]) }}</div>
            <p class="text-muted mb-0 mt-1">{{ __('candidate.grievance.review.lead') }}</p>
            @if ($cycle->status->value === 'open')
                {{-- 2026-11-23: each grievance is the AH's as soon as it is submitted. --}}
                <div class="alert alert-info py-1 px-2 mb-0 mt-2" id="verification-still-open">{{ __('candidate.grievance.review.still_open', ['date' => $cycle->deadline_at->format('d-m-Y H:i')]) }}</div>
            @endif
        </div></div>

        {{-- How a grievance moves, said once on top (2026-11-24). --}}
        <div class="card border-0 shadow-sm mb-3" id="grievance-steps"><div class="card-body small">
            <h2 class="h6">{{ __('candidate.grievance.flow.heading') }}</h2>
            <ol class="mb-0 ps-3">
                <li>{{ __('candidate.grievance.flow.step1') }}</li>
                <li>{{ __('candidate.grievance.flow.step2') }}</li>
                <li>{{ __('candidate.grievance.flow.step3') }}</li>
            </ol>
        </div></div>

        <form method="GET" class="row g-2 mb-2 small" id="grievance-filters">
            <div class="col-md-3"><select name="section" class="form-select form-select-sm"><option value="">{{ __('candidate.grievance.review.section') }}: {{ __('candidate.grievance.review.all') }}</option>
                @foreach (\App\Domain\CandidateVerification\Enums\VerificationSection::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['section'] ?? null) === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
            <div class="col-md-3"><select name="status" class="form-select form-select-sm"><option value="">{{ __('candidate.grievance.review.status') }}: {{ __('candidate.grievance.review.all') }}</option>
                @foreach (\App\Domain\CandidateVerification\Enums\GrievanceStatus::cases() as $st)@if ($st->value !== 'draft')<option value="{{ $st->value }}" @selected(($filters['status'] ?? null) === $st->value)>{{ $st->label() }}</option>@endif @endforeach</select></div>
            <div class="col-md-3"><input name="candidate" class="form-control form-control-sm" value="{{ $filters['candidate'] ?? '' }}" placeholder="{{ __('candidate.grievance.review.candidate') }}"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary">{{ __('candidate.grievance.review.all') }} ⟳</button></div>
        </form>

        {{-- Candidate-wise (2026-11-22); each grievance says where it stands and what comes next (2026-11-24). --}}
        @forelse ($grievances as $group)
            @include('dpc-proposals.partials.grievance-candidate', ['group' => $group])
        @empty
            <div class="card border-0 shadow-sm"><div class="card-body text-muted">{{ __('candidate.grievance.review.none') }}</div></div>
        @endforelse
    @endif
@endsection
