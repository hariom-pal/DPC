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
        </div></div>

        @if ($corrections->isNotEmpty())
            <div class="card border-0 shadow-sm mb-3" id="ao-corrections"><div class="card-body">
                <h2 class="h6">{{ __('candidate.grievance.review.ao_tasks') }}</h2>
                <p class="small text-muted">{{ __('candidate.grievance.review.ao_lead') }}</p>
                @foreach ($corrections as $correction)
                    @php
                        $row = $correction->grievance->participant->row;
                    @endphp
                    <div class="border rounded p-2 mb-2 small" data-correction="{{ $correction->id }}">
                        <strong>{{ $row->name }}</strong> ({{ $row->employee_number ?? '—' }}) · {{ $correction->section->label() }}
                        <div>{{ __('candidate.grievance.review.instructions') }}: {{ $correction->instructions }}</div>
                        <a class="btn btn-sm btn-outline-primary my-1" href="{{ route('dpc-proposals.employees.show', [$proposal, $row]) }}#section-{{ $correction->section->employeeSection() }}">{{ __('candidate.grievance.review.open_employee') }}</a>
                        <form method="POST" action="{{ route('dpc-proposals.grievances.correction', [$proposal, $correction]) }}" class="d-flex gap-2 align-items-start">
                            @csrf
                            <textarea class="form-control form-control-sm" name="ao_remarks" rows="2" maxlength="2000" required placeholder="{{ __('candidate.grievance.review.ao_remarks') }}"></textarea>
                            <button class="btn btn-sm btn-primary">{{ __('candidate.grievance.review.submit_correction') }}</button>
                        </form>
                    </div>
                @endforeach
            </div></div>
        @endif

        <form method="GET" class="row g-2 mb-2 small" id="grievance-filters">
            <div class="col-md-3"><select name="section" class="form-select form-select-sm"><option value="">{{ __('candidate.grievance.review.section') }}: {{ __('candidate.grievance.review.all') }}</option>
                @foreach (\App\Domain\CandidateVerification\Enums\VerificationSection::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['section'] ?? null) === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
            <div class="col-md-3"><select name="status" class="form-select form-select-sm"><option value="">{{ __('candidate.grievance.review.status') }}: {{ __('candidate.grievance.review.all') }}</option>
                @foreach (\App\Domain\CandidateVerification\Enums\GrievanceStatus::cases() as $st)@if ($st->value !== 'draft')<option value="{{ $st->value }}" @selected(($filters['status'] ?? null) === $st->value)>{{ $st->label() }}</option>@endif @endforeach</select></div>
            <div class="col-md-3"><input name="candidate" class="form-control form-control-sm" value="{{ $filters['candidate'] ?? '' }}" placeholder="{{ __('candidate.grievance.review.candidate') }}"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary">{{ __('candidate.grievance.review.all') }} ⟳</button></div>
        </form>

        {{-- Candidate-wise (2026-11-22): each candidate's grievances together, decided one by one. --}}
        @forelse ($grievances as $group)
            @php
                $row = $group->first()->participant->row;
                $openCount = $group->filter(fn ($g) => in_array($g->status->value, ['submitted', 'returned_from_ao'], true))->count();
            @endphp
            <div class="card border-0 shadow-sm mb-3" data-candidate="{{ $row->sn }}"><div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                <h2 class="h6 mb-0">{{ $row->name }} · {{ $row->employee_number ?? '—' }} · S.N. {{ $row->sn }}</h2>
                <span class="badge {{ $openCount > 0 ? 'text-bg-warning' : 'text-bg-success' }}">{{ __('candidate.grievance.review.candidate_count', ['count' => $group->count(), 'open' => $openCount]) }}</span>
            </div>
            @foreach ($group as $grievance)
            @php
                $decidable = in_array($grievance->status->value, ['submitted', 'returned_from_ao'], true) && $proposal->status->value === 'grievance_review';
            @endphp
            <div class="border rounded mb-2" data-grievance="{{ $grievance->uuid }}" data-status="{{ $grievance->status->value }}"><div class="p-2 small">
                <div class="d-flex flex-wrap justify-content-end gap-2">
                    <span class="badge text-bg-secondary">{{ $grievance->status->label() }}</span>
                </div>
                <div>{{ __('candidate.grievance.review.section') }}: {{ $grievance->section->label() }} · {{ __('candidate.grievance.review.category') }}: {{ __('candidate.grievance.categories.'.$grievance->category) }}@if ($grievance->related_year) · {{ $grievance->related_year }}@endif</div>
                <div>{{ __('candidate.grievance.review.remark') }}: {{ $grievance->remark }}</div>
                <div>{{ __('candidate.grievance.review.document') }}: <a href="{{ route('dpc-proposals.grievances.document', [$proposal, $grievance]) }}">{{ $grievance->original_name }}</a> · {{ __('candidate.grievance.review.submitted') }}: {{ $grievance->submitted_at?->format('d-m-Y H:i') }}</div>
                @foreach ($grievance->corrections as $c)
                    <div class="text-muted">{{ __('candidate.grievance.review.correction') }}: {{ $c->instructions }} @if ($c->submitted_at) → {{ $c->ao_remarks }} ({{ $c->submitted_at->format('d-m-Y') }})@endif</div>
                @endforeach
                @if ($grievance->decision)
                    <div>{{ __('candidate.grievance.review.resolution') }}: <strong>{{ $grievance->decision->label() }}</strong> — {{ $grievance->resolution_remark }} ({{ $grievance->resolved_at?->format('d-m-Y') }}, {{ $grievance->resolver?->name }})</div>
                @endif
                @if ($decidable)
                    <div class="row g-2 mt-1">
                        <form method="POST" action="{{ route('dpc-proposals.grievances.resolve', [$proposal, $grievance]) }}" class="col-md-6">
                            @csrf
                            <select name="decision" class="form-select form-select-sm mb-1" required>
                                @foreach (\App\Domain\CandidateVerification\Enums\GrievanceDecision::cases() as $d)<option value="{{ $d->value }}">{{ $d->label() }}</option>@endforeach
                            </select>
                            <textarea name="resolution_remark" class="form-control form-control-sm mb-1" rows="2" maxlength="2000" required placeholder="{{ __('candidate.grievance.review.resolution_remark') }}"></textarea>
                            <button class="btn btn-sm btn-success">{{ __('candidate.grievance.review.decide') }}</button>
                        </form>
                        <form method="POST" action="{{ route('dpc-proposals.grievances.send-to-ao', [$proposal, $grievance]) }}" class="col-md-6">
                            @csrf
                            <textarea name="instructions" class="form-control form-control-sm mb-1" rows="3" maxlength="2000" required placeholder="{{ __('candidate.grievance.review.instructions') }}"></textarea>
                            <button class="btn btn-sm btn-outline-warning">{{ __('candidate.grievance.review.send_to_ao') }}</button>
                        </form>
                    </div>
                @endif
            </div></div>
            @endforeach
            </div></div>
        @empty
            <div class="card border-0 shadow-sm"><div class="card-body text-muted">{{ __('candidate.grievance.review.none') }}</div></div>
        @endforelse
    @endif
@endsection
