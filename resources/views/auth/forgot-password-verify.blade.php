@extends('layouts.portal')

@section('title', __('authui.forgot.title'))

@section('content')
    <div class="py-5" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
        <div class="container py-lg-4">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-5">
                    <div class="gov-card p-4 p-lg-5">
                        <h1 class="h4 mb-1">{{ __('authui.forgot.title') }}</h1>
                        <p class="text-body-secondary small">
                            {{ $mobileCodesUsable ? __('authui.forgot.verify_lead', ['login' => $login]) : __('authui.forgot.verify_lead_no_sms', ['login' => $login]) }}
                        </p>

                        @include('partials.alerts')

                        <form method="POST" action="{{ route('password.forgot.update') }}" id="forgot-password-verify">
                            @csrf
                            <div class="row g-2 mb-3">
                                <div class="col">
                                    <label for="email_code" class="form-label">{{ __('authui.forgot.email_code') }}</label>
                                    <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" class="form-control @error('email_code') is-invalid @enderror" id="email_code" name="email_code" required>
                                    @error('email_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                @if ($mobileCodesUsable)
                                    <div class="col">
                                        <label for="mobile_code" class="form-label">{{ __('authui.forgot.mobile_code') }}</label>
                                        <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" class="form-control @error('mobile_code') is-invalid @enderror" id="mobile_code" name="mobile_code" required>
                                        @error('mobile_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endif
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">{{ __('authui.forgot.new_password') }}</label>
                                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" autocomplete="new-password" required>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">{{ __('authui.forgot.password_rule') }}</div>
                            </div>
                            <div class="mb-3">
                                <label for="password_confirmation" class="form-label">{{ __('authui.forgot.confirm_password') }}</label>
                                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">{{ __('authui.forgot.reset') }}</button>
                        </form>

                        <div class="d-flex justify-content-between small mt-3">
                            <form method="POST" action="{{ route('password.forgot.resend') }}">@csrf<button class="btn btn-link btn-sm p-0">{{ __('authui.forgot.resend') }}</button></form>
                            <a href="{{ route('login') }}">{{ __('authui.forgot.back_to_login') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
