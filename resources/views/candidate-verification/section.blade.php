@extends('layouts.portal')
@section('title', $section->label())
@section('content')
    @include('candidate-verification.partials.shell-start', ['signedIn' => true])
    @include('candidate-verification.partials.progress')

    <div class="gov-card p-3 mb-3" id="section-{{ $section->value }}" data-section="{{ $section->value }}">
        <h2 class="h6">{{ $section->label() }}</h2>
        @include('candidate-verification.partials.section-'.$section->value)
    </div>

    @if ($corrected)
        <div class="alert alert-info small" id="corrected-notice">
            <strong>{{ __('candidate.verification.corrected.heading') }}</strong> —
            {{ __('candidate.verification.corrected.lead', ['date' => $corrected->created_at?->format('d-m-Y')]) }}
        </div>
    @endif

    {{-- Several grievances a section (2026-11-22): one for each year that is wrong. --}}
    @php
        $actionable = ! $participant->hasSubmitted() && $participant->cycle->acceptsSubmissions();
    @endphp
    @foreach ($grievances as $grievance)
        <div class="gov-card p-3 mb-3 border-warning" id="grievance-summary-{{ $loop->iteration }}" data-grievance="{{ $grievance->uuid }}">
            <div class="small"><strong>{{ __('candidate.grievance.category') }}:</strong> {{ __('candidate.grievance.categories.'.$grievance->category) }}@if ($grievance->related_year) · {{ $grievance->related_year }}@endif</div>
            <div class="small"><strong>{{ __('candidate.grievance.remark') }}:</strong> {{ $grievance->remark }}</div>
            <div class="small"><strong>{{ __('candidate.grievance.document') }}:</strong>
                <a href="{{ route('candidate-verification.grievance.document', [$participant->uuid, $section->value, 'grievance' => $grievance->uuid]) }}">{{ $grievance->original_name }}</a></div>
            {{-- Under review until the AH resolves it (2026-11-24); then the decision and its reason. --}}
            <div class="small"><span class="badge {{ $grievance->status->value === 'resolved' ? 'text-bg-success' : 'text-bg-warning' }}" data-candidate-grievance-status="{{ $grievance->status->value }}">{{ $grievance->candidateStatusLabel() }}</span>
                @if ($grievance->status->value === 'resolved' && $grievance->resolution_remark) {{ $grievance->resolution_remark }}@endif</div>
            @if ($actionable && $grievance->status->value === 'draft')
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <a class="btn btn-sm btn-outline-warning" href="{{ route('candidate-verification.grievance', [$participant->uuid, $section->value, 'grievance' => $grievance->uuid]) }}" data-edit-grievance="{{ $grievance->uuid }}">{{ __('candidate.verification.actions.edit_grievance') }}</a>
                    <form method="POST" action="{{ route('candidate-verification.grievance.remove', [$participant->uuid, $section->value]) }}" class="m-0">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="grievance" value="{{ $grievance->uuid }}">
                        <button class="btn btn-sm btn-outline-danger">{{ __('candidate.verification.actions.remove_grievance') }}</button>
                    </form>
                </div>
            @endif
        </div>
    @endforeach

    @if ($actionable)
        <div class="d-flex flex-wrap gap-2">
            <form method="POST" action="{{ route('candidate-verification.agree', [$participant->uuid, $section->value]) }}" class="m-0">
                @csrf
                <button class="btn btn-success" id="agree-next">{{ __('candidate.verification.actions.agree_next') }}</button>
            </form>
            <a class="btn btn-outline-warning" id="raise-grievance" href="{{ route('candidate-verification.grievance', [$participant->uuid, $section->value]) }}">
                {{ $grievances->isNotEmpty() ? __('candidate.verification.actions.add_grievance') : __('candidate.verification.actions.raise') }}
            </a>
        </div>
    @endif
    @include('candidate-verification.partials.shell-end')
@endsection
