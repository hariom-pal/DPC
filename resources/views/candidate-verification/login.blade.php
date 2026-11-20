@extends('layouts.portal')
@section('title', __('candidate.verification.title'))
@section('content')
    @include('candidate-verification.partials.shell-start')
    <div class="gov-card p-4" style="max-width: 520px">
        <h2 class="h6">{{ __('candidate.verification.login.heading') }}</h2>
        <p class="small text-body-secondary">{{ __('candidate.verification.login.lead') }}</p>
        <form method="POST" action="{{ route('candidate-verification.code.send') }}" id="candidate-login">
            @csrf
            <label class="form-label" for="mobile">{{ __('candidate.verification.login.mobile') }}</label>
            <input type="tel" class="form-control mb-3" id="mobile" name="mobile" inputmode="numeric" autocomplete="tel" maxlength="20" required value="{{ old('mobile') }}">
            <button class="btn btn-primary">{{ __('candidate.verification.login.send') }}</button>
        </form>
    </div>
    @include('candidate-verification.partials.shell-end')
@endsection
