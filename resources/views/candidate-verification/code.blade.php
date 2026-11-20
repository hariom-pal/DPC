@extends('layouts.portal')
@section('title', __('candidate.verification.title'))
@section('content')
    @include('candidate-verification.partials.shell-start')
    <div class="gov-card p-4" style="max-width: 520px">
        <form method="POST" action="{{ route('candidate-verification.verify') }}" id="candidate-otp">
            @csrf
            <label class="form-label" for="code">{{ __('candidate.verification.login.code') }}</label>
            <input type="text" class="form-control mb-3" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required>
            <button class="btn btn-primary">{{ __('candidate.verification.login.verify') }}</button>
            <a href="{{ route('candidate-verification.login') }}" class="btn btn-link">{{ __('candidate.verification.actions.back') }}</a>
        </form>
    </div>
    @include('candidate-verification.partials.shell-end')
@endsection
