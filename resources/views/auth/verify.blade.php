@extends('layouts.app')

@section('title', __('authui.verify.otp_verification').' — '.__('messages.app_name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3 text-center">{{ __('authui.verify.otp_verification') }}</h1>

                    @include('partials.alerts')

                    <form method="POST" action="{{ route('verify.store') }}">
                        @csrf
                        @if ($needsEmail)
                            <div class="mb-3">
                                <label for="email_code" class="form-label">
                                    {{ __('authui.verify.email_otp_sent_to', ['email' => $user->email]) }}
                                </label>
                                <input type="text" class="form-control" id="email_code" name="email_code"
                                       inputmode="numeric" maxlength="{{ config('dpc.otp.length') }}" required autofocus>
                            </div>
                        @endif
                        @if ($needsMobile)
                            <div class="mb-3">
                                <label for="mobile_code" class="form-label">
                                    {{ __('authui.verify.mobile_otp_sent_to', ['mobile' => $user->mobile]) }}
                                </label>
                                <input type="text" class="form-control" id="mobile_code" name="mobile_code"
                                       inputmode="numeric" maxlength="{{ config('dpc.otp.length') }}" required>
                            </div>
                        @elseif ($user->hasMobileWaiver())
                            <p class="small text-body-secondary">{{ __('authui.verify.mobile_waived') }}</p>
                        @endif
                        <button type="submit" class="btn btn-success w-100">{{ __('authui.verify.verify') }}</button>
                    </form>

                    <form method="POST" action="{{ route('verify.resend') }}" class="mt-2 text-center">
                        @csrf
                        <button type="submit" class="btn btn-link btn-sm">{{ __('authui.verify.resend') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
