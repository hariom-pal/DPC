@extends('layouts.portal')
@section('title', __('candidate.verification.title'))
@section('content')
    @include('candidate-verification.partials.shell-start')
    <div class="gov-card p-4" style="max-width: 520px">
        @if ($tail)
            <p class="small text-body-secondary mb-2" id="otp-sent-to">{{ __('candidate.verification.login.sent_to', ['tail' => $tail]) }}</p>
        @endif
        <form method="POST" action="{{ route('candidate-verification.verify') }}" id="candidate-otp">
            @csrf
            <label class="form-label" for="code">{{ __('candidate.verification.login.code') }}</label>
            <input type="text" class="form-control mb-3" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required>
            <button class="btn btn-primary">{{ __('candidate.verification.login.verify') }}</button>
        </form>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
            <form method="POST" action="{{ route('candidate-verification.code.resend') }}" class="m-0" id="candidate-otp-resend">
                @csrf
                <button class="btn btn-outline-secondary btn-sm">{{ __('candidate.verification.login.resend') }}</button>
            </form>
            <a href="{{ route('candidate-verification.login') }}" class="btn btn-link btn-sm">{{ __('candidate.verification.login.change_number') }}</a>
        </div>
        <p class="small text-body-secondary mt-3 mb-0">{{ __('candidate.verification.login.not_received') }}</p>
    </div>
    @include('candidate-verification.partials.shell-end')
@endsection
