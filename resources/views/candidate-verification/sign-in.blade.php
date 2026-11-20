@extends('layouts.portal')
@section('title', __('candidate.verification.title'))
@section('content')
    @include('candidate-verification.partials.shell-start')
    <div class="gov-card p-4" style="max-width: 560px" id="sign-in-esign">
        <h2 class="h6">{{ __('candidate.verification.login.esign_heading') }}</h2>
        <p class="small text-body-secondary">{{ __('candidate.verification.login.esign_lead') }}</p>
        <form method="POST" action="{{ route('candidate-verification.sign-in.store') }}">
            @csrf
            <p class="small text-body-secondary mb-2">{{ __('candidate.verification.review.esign_heading') }}</p>
            <label class="form-label small" for="name">{{ __('candidate.verification.review.esign_name') }}</label>
            <input type="text" class="form-control mb-2" id="name" name="name" value="{{ old('name', $name) }}" maxlength="255">
            <button class="btn btn-primary" name="decision" value="sign">{{ __('candidate.verification.review.esign_sign') }}</button>
            <button class="btn btn-outline-secondary" name="decision" value="cancel" formnovalidate>{{ __('candidate.verification.review.esign_cancel') }}</button>
        </form>
    </div>
    @include('candidate-verification.partials.shell-end')
@endsection
