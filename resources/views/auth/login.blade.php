@extends('layouts.portal')

@section('title', 'Login — e-DPC Portal')

@section('content')
    <div class="py-5" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
        <div class="container py-lg-4">
            <div class="row justify-content-center g-4 align-items-center">

                {{-- Reassurance panel. A login screen on a government portal
                     is where a first-time user decides whether to trust it. --}}
                <div class="col-lg-5 order-lg-1 order-2">
                    <div class="gov-card p-4 h-100">
                        <h2 class="h6 mb-3">Signing in</h2>
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
                            <h1 class="h4 mb-1">Login</h1>
                            <p class="text-body-secondary small mb-0">लॉग इन · Departmental Promotion Committee Portal</p>
                        </div>

                        @include('partials.alerts')

                        <form method="POST" action="{{ route('login.store') }}">
                            @csrf

                            <div class="mb-3">
                                <label for="login" class="form-label">Email or Mobile / ईमेल या मोबाइल</label>
                                <input type="text" class="form-control @error('login') is-invalid @enderror"
                                       id="login" name="login" value="{{ old('login') }}"
                                       autocomplete="username" required autofocus>
                                @error('login')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Password / पासवर्ड</label>
                                <input type="password" class="form-control @error('password') is-invalid @enderror"
                                       id="password" name="password" autocomplete="current-password" required>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                                <label class="form-check-label small" for="remember">Remember me on this device</label>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100">Login</button>
                        </form>

                        <hr class="my-4">

                        <p class="text-center text-body-secondary small mb-0">
                            New department?
                            <a href="{{ route('register') }}" class="fw-semibold">Register here</a>
                        </p>
                    </div>

                    <p class="text-center small text-body-secondary mt-3 mb-0">
                        Trouble signing in? Contact your department's nodal officer.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
