@extends('layouts.portal')
@section('title', __('candidate.verification.review.heading'))
@section('content')
    @include('candidate-verification.partials.shell-start', ['signedIn' => true])
    @include('candidate-verification.partials.progress')
    <div class="gov-card p-3 mb-3" id="final-review">
        <h2 class="h6">{{ __('candidate.verification.review.heading') }}</h2>
        @if ($participant->hasSubmitted())
            <div class="alert alert-success small" id="submitted">{{ __('candidate.verification.review.submitted', ['date' => $participant->submitted_at->format('d-m-Y H:i')]) }}
                <a href="{{ route('candidate-verification.acknowledgement', $participant->uuid) }}" class="ms-2">{{ __('candidate.verification.actions.acknowledgement') }}</a></div>
        @else
            <p class="small text-body-secondary">{{ __('candidate.verification.review.lead') }}</p>
        @endif
        <table class="table table-sm small">
            <thead><tr><th>{{ __('candidate.verification.review.section') }}</th><th>{{ __('candidate.verification.review.decision') }}</th></tr></thead>
            <tbody>
                @foreach (\App\Domain\CandidateVerification\Enums\VerificationSection::cases() as $step)
                    <tr data-review="{{ $step->value }}">
                        <td>{{ $step->label() }}</td>
                        <td>
                            {{ $participant->decisionLabel($decisions[$step->value] ?? null) }}
                            @foreach ($grievances->get($step->value, collect()) as $g)
                                <div class="text-body-secondary" data-review-grievance="{{ $g->uuid }}">{{ __('candidate.grievance.categories.'.$g->category) }}@if ($g->related_year) · {{ $g->related_year }}@endif — {{ $g->remark }}</div>
                                <div><a href="{{ route('candidate-verification.grievance.document', [$participant->uuid, $step->value, 'grievance' => $g->uuid]) }}">{{ $g->original_name }}</a>
                                    · <span class="badge text-bg-light border">{{ $g->candidateStatusLabel() }}</span>
                                    @if ($g->status->value === 'resolved' && $g->resolution_remark) {{ $g->resolution_remark }}@endif</div>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (! $participant->hasSubmitted() && $participant->cycle->acceptsSubmissions())
            @if ($grievances->isNotEmpty())
                <div class="border rounded p-2 small mb-2">
                    <strong>{{ __('candidate.grievance.undertaking_label') }}</strong>
                    <p class="mb-1">{{ __('candidate.grievance.undertaking', [], 'en') }}</p>
                    <p class="mb-0">{{ __('candidate.grievance.undertaking_hi') }}</p>
                </div>
            @endif
            <form method="POST" action="{{ route('candidate-verification.submit', $participant->uuid) }}" id="final-submit">
                @csrf
                <label class="form-check small"><input type="checkbox" class="form-check-input" name="declaration" value="1" required> <span class="form-check-label">{{ __('candidate.verification.review.declaration') }}</span></label>
                <label class="form-check small mb-2"><input type="checkbox" class="form-check-input" name="consent" value="1" required> <span class="form-check-label">{{ __('candidate.verification.review.consent') }}</span></label>
                <button class="btn btn-primary" @disabled($blockers !== [])>{{ __('candidate.verification.actions.submit') }}</button>
                @foreach ($blockers as $blocker)<div class="small text-danger">{{ $blocker }}</div>@endforeach
            </form>
        @endif
    </div>
    @include('candidate-verification.partials.shell-end')
@endsection
