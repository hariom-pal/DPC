@extends('layouts.portal')
@section('title', $section->label())
@section('content')
    @include('candidate-verification.partials.shell-start', ['signedIn' => true])
    <nav class="d-flex flex-wrap gap-2 mb-3" id="record-sections">
        <a href="{{ route('candidate-verification.dashboard') }}" class="badge text-bg-light border text-decoration-none p-2">{{ __('candidate.verification.actions.back') }}</a>
        @foreach (\App\Domain\CandidateVerification\Enums\VerificationSection::cases() as $step)
            <a href="{{ route('candidate-verification.record', [$row->id, $step->value]) }}"
               class="badge {{ $step === $section ? 'text-bg-primary' : 'text-bg-light border' }} text-decoration-none p-2">{{ $loop->iteration }}. {{ $step->label() }}</a>
        @endforeach
    </nav>

    <div class="gov-card p-3 mb-3" id="section-{{ $section->value }}" data-section="{{ $section->value }}">
        <h2 class="h6">{{ $section->label() }}</h2>
        @include('candidate-verification.partials.section-'.$section->value)
    </div>
    <p class="small text-body-secondary" id="read-only-note">{{ __('candidate.verification.dashboard.read_only') }}</p>
    @include('candidate-verification.partials.shell-end')
@endsection
