@extends('layouts.portal')

@section('title', __('invitations.register.title'))

@section('content')
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <h1 class="h4 mb-1">{{ __('invitations.register.title') }}</h1>
                <p class="text-body-secondary small">{{ __('invitations.register.step2') }}</p>

                @include('partials.alerts')

                <div class="gov-card">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('register.invitation.verify.store', ['token' => $token]) }}">
                            @csrf
                            <div class="mb-3">
                                <label for="email_code" class="form-label">{{ __('authui.verify.email_otp_sent_to', ['email' => $invitation->email]) }}</label>
                                <input type="text" class="form-control @error('email_code') is-invalid @enderror" id="email_code" name="email_code" inputmode="numeric" maxlength="{{ config('dpc.otp.length') }}" required autofocus>
                                @error('email_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label for="mobile_code" class="form-label">{{ __('authui.verify.mobile_otp_sent_to', ['mobile' => $details['mobile']]) }}</label>
                                <input type="text" class="form-control @error('mobile_code') is-invalid @enderror" id="mobile_code" name="mobile_code" inputmode="numeric" maxlength="{{ config('dpc.otp.length') }}" required>
                                @error('mobile_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-success w-100">{{ __('invitations.register.verify_otp') }}</button>
                        </form>
                        <form method="POST" action="{{ route('register.invitation.resend', ['token' => $token]) }}" class="mt-2 text-center">
                            @csrf
                            <button type="submit" class="btn btn-link btn-sm">{{ __('authui.verify.resend') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
