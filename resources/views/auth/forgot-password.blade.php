@extends('layouts.portal')

@section('title', __('authui.forgot.title'))

@section('content')
    <div class="py-5" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
        <div class="container py-lg-4">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-5">
                    <div class="gov-card p-4 p-lg-5">
                        <h1 class="h4 mb-1">{{ __('authui.forgot.title') }}</h1>
                        <p class="text-body-secondary small">{{ __('authui.forgot.lead') }}</p>

                        @include('partials.alerts')

                        <form method="POST" action="{{ route('password.forgot.send') }}" id="forgot-password">
                            @csrf
                            <div class="mb-3">
                                <label for="login" class="form-label">{{ __('authui.login.email_or_mobile') }}</label>
                                <input type="text" class="form-control @error('login') is-invalid @enderror" id="login" name="login"
                                       value="{{ old('login') }}" autocomplete="username" required autofocus>
                                @error('login')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-primary w-100">{{ __('authui.forgot.send') }}</button>
                        </form>

                        <p class="text-center small mt-3 mb-0"><a href="{{ route('login') }}">{{ __('authui.forgot.back_to_login') }}</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
