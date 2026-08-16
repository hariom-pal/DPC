<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b4f9e">
    <title>@yield('title', __('messages.app_name'))</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/portal.css') }}">
    @stack('styles')
</head>
<body class="d-flex flex-column min-vh-100">
<a class="skip-link" href="#main">Skip to main content</a>

<header class="sticky-header">
    <nav class="app-shell-header navbar navbar-expand-lg navbar-dark shadow-sm" aria-label="Main navigation">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('home') }}">
                <img src="{{ asset('images/logo/cgpsc-logo.svg') }}" alt=""
                     style="height:34px" class="bg-white rounded-circle p-1">
                <span class="d-flex flex-column lh-sm">
                    <span class="fw-semibold">e-DPC Portal</span>
                    <span class="small fw-normal" style="font-size:.72rem;opacity:.85">
                        Chhattisgarh Public Service Commission
                    </span>
                </span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#appNav" aria-controls="appNav" aria-expanded="false"
                    aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="appNav">
                <div class="ms-auto d-flex flex-wrap align-items-lg-center gap-2 py-2 py-lg-0">
                    @auth
                        <span class="nav-user d-none d-md-inline me-1">
                            {{ auth()->user()->name }}
                        </span>
                        <a class="btn btn-sm btn-outline-light" href="{{ route('dashboard') }}">Dashboard</a>
                        <form method="POST" action="{{ route('logout') }}" class="m-0" data-no-busy>
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-light">Logout</button>
                        </form>
                    @else
                        <a class="btn btn-sm btn-outline-light" href="{{ route('login') }}">Login</a>
                        <a class="btn btn-sm btn-accent" href="{{ route('register') }}">Register</a>
                    @endauth

                    <span class="vr text-white-50 d-none d-lg-inline mx-1"></span>

                    <span class="d-flex gap-1">
                        <a class="btn btn-sm {{ app()->getLocale() === 'en' ? 'btn-light' : 'btn-outline-light' }}"
                           href="{{ route('lang.switch', 'en') }}">EN</a>
                        <a class="btn btn-sm {{ app()->getLocale() === 'hi' ? 'btn-light' : 'btn-outline-light' }}"
                           href="{{ route('lang.switch', 'hi') }}">हिंदी</a>
                    </span>
                </div>
            </div>
        </div>
    </nav>
</header>

<main id="main" class="container py-4 flex-grow-1">
    {{-- Architect Directive 3 (M8): "THIS IS A REVIEW DPC" throughout the
         Review workflow. Driven by a view composer rather than by editing
         each screen, because the M4 proposal views are frozen. --}}
    @includeWhen(($isReviewDpc ?? false), 'partials.review-banner')
    @yield('content')
</main>

<footer class="gov-footer-bottom py-3 mt-auto">
    <div class="container d-flex flex-wrap justify-content-between gap-2">
        <span>&copy; {{ now()->year }} Chhattisgarh Public Service Commission · e-DPC Portal</span>
        <span class="text-white-50">Version {{ config('dpc.application.version', '1.0') }}</span>
    </div>
</footer>

<script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/portal.js') }}" defer></script>
@stack('scripts')
</body>
</html>
