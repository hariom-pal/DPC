{{-- The candidates' verification and grievances (2026-11-19), for the DPC's readers. --}}
@php
    $verificationCycle = \App\Domain\CandidateVerification\Models\VerificationCycle::query()->where('dpc_proposal_id', $proposal->id)->first();
@endphp
@if ($verificationCycle)
    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2 small" id="candidate-verification-card">
        <div>
            <strong>{{ __('candidate.verification.title') }}</strong> —
            {{ __('candidate.verification.dashboard.deadline') }}: {{ $verificationCycle->deadline_at->format('d-m-Y') }} ·
            {{ __('candidate.grievance.review.summary', ['submitted' => $verificationCycle->participants()->whereNotNull('submitted_at')->count(), 'total' => $verificationCycle->participant_count, 'grievances' => $verificationCycle->grievances()->where('status', '!=', 'draft')->count(), 'no_response' => $verificationCycle->participants()->where('status', 'no_response')->count()]) }}
        </div>
        <a class="btn btn-sm btn-outline-primary" href="{{ route('dpc-proposals.grievances.index', $proposal) }}" id="grievances-link">{{ __('candidate.grievance.review.heading') }}</a>
    </div>
@endif
