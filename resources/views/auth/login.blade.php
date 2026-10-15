@extends('layouts.portal')

@section('title', __('authui.login.login_e_dpc_portal'))

@section('content')
    <div class="py-5" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
        <div class="container py-lg-4">
            <div class="row justify-content-center g-4 align-items-center">

                {{-- Reassurance panel. A login screen on a government portal
                     is where a first-time user decides whether to trust it. --}}
                <div class="col-lg-5 order-lg-1 order-2">
                    <div class="gov-card p-4 h-100">
                        <h2 class="h6 mb-3">{{ __('authui.login.signing_in') }}</h2>
                        <ul class="list-unstyled d-grid gap-3 mb-0">
                            @foreach ([
                                ['lock', 'Your session is protected', 'Sign-in is rate limited and your session is regenerated on every login.'],
                                ['signature', 'Actions are signed', 'Legally significant approvals require an OTP step-up, recorded as evidence of intent.'],
                                ['audit', 'Everything is recorded', 'Who did what, and when, is written to a permanent audit trail.'],
                            ] as [$icon, $title, $text])
                                <li class="d-flex gap-3">
                                    <span class="gov-icon flex-shrink-0">
                                        @include('partials.icon', ['name' => $icon, 'size' => 20])
                                    </span>
                                    <span>
                                        <strong class="d-block small">{{ $title }}</strong>
                                        <span class="small text-body-secondary">{{ $text }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="col-lg-5 order-lg-2 order-1">
                    <div class="gov-card p-4 p-lg-5">
                        <div class="text-center mb-4">
                            @include('partials.logo', [
                                'which' => 'cgpsc', 'class' => 'mb-3', 'style' => 'height:56px', 'alt' => '',
                            ])
                            <h1 class="h4 mb-1">{{ __('ui.login') }}</h1>
                            <p class="text-body-secondary small mb-0">{{ __('authui.login.departmental_promotion_committee_portal') }}</p>
                        </div>

                        @include('partials.alerts')

                        <form method="POST" action="{{ route('login.store') }}">
                            @csrf

                            <div class="mb-3">
                                <label for="login" class="form-label">{{ __('authui.login.email_or_mobile') }}</label>
                                <input type="text" class="form-control @error('login') is-invalid @enderror"
                                       id="login" name="login" value="{{ old('login') }}"
                                       autocomplete="username" required autofocus>
                                @error('login')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">{{ __('authui.login.password') }}</label>
                                <input type="password" class="form-control @error('password') is-invalid @enderror"
                                       id="password" name="password" autocomplete="current-password" required>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                                <label class="form-check-label small" for="remember">{{ __('authui.login.remember_me_on_this_device') }}</label>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100">{{ __('ui.login') }}</button>
                        </form>

                        <hr class="my-4">

                        <p class="text-center text-body-secondary small mb-2">
{{ __('authui.login.new_department') }}
                            <a href="{{ route('register') }}" class="fw-semibold">{{ __('authui.login.register_here') }}</a>
                        </p>

                        {{-- S49/S50. An officer under consideration has no account
                             here and will otherwise try to log in with one. --}}
                        <p class="text-center text-body-secondary small mb-0">
{{ __('authui.login.officer_under_consideration') }}
                            <a href="{{ route('self-inspection.landing') }}" class="fw-semibold">{{ __('authui.login.view_your_own_data') }}</a>
                        </p>
                    </div>

                    <p class="text-center small text-body-secondary mt-3 mb-0">
                        {{ __('authui.login.trouble_signing_in_contact_your_department_s_nod') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
