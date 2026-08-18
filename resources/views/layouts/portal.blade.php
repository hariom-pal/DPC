<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Online Departmental Promotion Committee (e-DPC) Portal — Chhattisgarh Public Service Commission.">
    <meta name="theme-color" content="#0b4f9e">
    <title>@yield('title', __('messages.app_name'))</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/portal.css') }}">
    @stack('styles')
</head>
<body class="d-flex flex-column min-vh-100">
{{-- Assessed on every government portal, and cheap to get right. --}}
<a class="skip-link" href="#main">Skip to main content</a>

<header class="sticky-header">
    {{-- Utility bar --}}
    <div class="gov-topbar">
        <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2 py-1">
            <div class="d-flex align-items-center gap-3">
                <span class="d-none d-md-inline">भारत सरकार की मानक शासकीय वेब सेवा · Government Web Service</span>
                <a href="#help-desk" class="d-inline d-md-none">Help Desk</a>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="#help-desk" class="d-none d-md-inline">Help Desk</a>
                <a href="#contact" class="d-none d-md-inline">Contact</a>
                <a href="#accessibility" class="d-none d-lg-inline">Accessibility</a>

                <span class="d-flex align-items-center gap-1" id="accessibility">
                    <span class="d-none d-sm-inline me-1">Font Size</span>
                    <button type="button" class="btn-font" data-font="sm" aria-label="Normal font size">A-</button>
                    <button type="button" class="btn-font" data-font="md" aria-label="Default font size" aria-pressed="true">A</button>
                    <button type="button" class="btn-font" data-font="lg" aria-label="Large font size">A+</button>
                </span>

                <span class="d-flex align-items-center gap-1">
                    <a href="{{ route('lang.switch', 'en') }}"
                       class="{{ app()->getLocale() === 'en' ? 'fw-bold text-white' : '' }}">EN</a>
                    <span aria-hidden="true">|</span>
                    <a href="{{ route('lang.switch', 'hi') }}"
                       class="{{ app()->getLocale() === 'hi' ? 'fw-bold text-white' : '' }}">हिंदी</a>
                </span>
            </div>
        </div>
    </div>

    {{-- Masthead: CGPSC left, title centre, Digital India right --}}
    <div class="gov-masthead">
        <div class="container py-2 py-lg-3">
            <div class="row align-items-center g-3">
                <div class="col-auto">
                    @include('partials.logo', [
                        'which' => 'cgpsc', 'class' => 'gov-logo',
                        'alt' => 'Chhattisgarh Public Service Commission',
                    ])
                </div>
                <div class="col text-center">
                    <div class="brand-title">छत्तीसगढ़ लोक सेवा आयोग · Chhattisgarh Public Service Commission</div>
                    <div class="brand-sub">Departmental Promotion Committee Management System (e-DPC)</div>
                    <div class="brand-hi">विभागीय पदोन्नति समिति प्रबंधन प्रणाली</div>
                </div>
                <div class="col-auto d-none d-sm-block">
                    @include('partials.logo', [
                        'which' => 'digital-india', 'class' => 'gov-logo-sm',
                        'alt' => 'Digital India',
                    ])
                </div>
            </div>
        </div>
    </div>

    {{-- Primary navigation --}}
    <nav class="gov-nav navbar navbar-expand-lg py-0" aria-label="Main navigation">
        <div class="container">
            <button class="navbar-toggler my-2" type="button" data-bs-toggle="collapse"
                    data-bs-target="#govNav" aria-controls="govNav" aria-expanded="false"
                    aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="govNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link active" href="{{ route('home') }}">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About DPC</a></li>
                    <li class="nav-item"><a class="nav-link" href="#process">Process Flow</a></li>
                    <li class="nav-item"><a class="nav-link" href="#roles">User Roles</a></li>
                    <li class="nav-item"><a class="nav-link" href="#manual">User Manual</a></li>
                    <li class="nav-item"><a class="nav-link" href="#faq">FAQ</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                </ul>
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2 py-2 py-lg-0">
                    <li class="nav-item">
                        <a class="btn btn-sm btn-outline-light w-100" href="{{ route('login') }}">Department Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-sm btn-accent w-100" href="{{ route('register') }}">New Registration</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</header>

<main id="main" class="flex-grow-1">
    @yield('content')
</main>

<footer class="gov-footer pt-5 pb-4 mt-auto">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    @include('partials.logo', [
                        'which' => 'cgpsc', 'class' => 'gov-logo-sm bg-white rounded p-1', 'alt' => '',
                    ])
                    <div>
                        <h2 class="mb-1">Chhattisgarh Public Service Commission</h2>
                        <div class="small">छत्तीसगढ़ लोक सेवा आयोग</div>
                    </div>
                </div>
                <p class="small mb-0">
                    Shankar Nagar Road, Raipur, Chhattisgarh.<br>
                    The e-DPC Portal conducts the departmental promotion process online, end to end.
                </p>
            </div>

            <div class="col-6 col-lg-2">
                <h2 class="mb-3">Portal</h2>
                <ul class="list-unstyled small d-grid gap-2 mb-0">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li><a href="#about">About DPC</a></li>
                    <li><a href="#process">Process Flow</a></li>
                    <li><a href="#roles">User Roles</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <h2 class="mb-3">Support</h2>
                <ul class="list-unstyled small d-grid gap-2 mb-0">
                    <li><a href="#manual">User Manual</a></li>
                    <li><a href="#faq">FAQ</a></li>
                    <li><a href="#help-desk">Help Desk</a></li>
                    <li><a href="#accessibility">Accessibility</a></li>
                </ul>
            </div>

            <div class="col-lg-4" id="help-desk">
                <h2 class="mb-3">Help Desk</h2>
                <ul class="list-unstyled small d-grid gap-2 mb-3">
                    <li class="d-flex gap-2">@include('partials.icon', ['name' => 'phone', 'size' => 16])
                        <span>Office hours, working days</span></li>
                    <li class="d-flex gap-2">@include('partials.icon', ['name' => 'mail', 'size' => 16])
                        <span>Through your department's nodal officer</span></li>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <span class="small">Designed &amp; developed with</span>
                    @include('partials.logo', [
                        'which' => 'csc', 'class' => 'bg-white rounded p-1',
                        'style' => 'height:34px',
                        'alt' => 'CSC e-Governance Services India Limited',
                    ])
                </div>
            </div>
        </div>
    </div>

    <div class="gov-footer-bottom mt-4 py-3">
        <div class="container d-flex flex-wrap justify-content-between gap-2">
            <div>&copy; {{ now()->year }} Chhattisgarh Public Service Commission. All rights reserved.</div>
            <div class="d-flex flex-wrap gap-3">
                <a href="#privacy">Privacy Policy</a>
                <a href="#terms">Terms of Use</a>
                <a href="#help-desk">Help Desk</a>
                <span class="text-white-50">Version {{ config('dpc.application.version', '1.0') }}</span>
            </div>
        </div>
    </div>
</footer>

<script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/portal.js') }}" defer></script>
@stack('scripts')
</body>
</html>
