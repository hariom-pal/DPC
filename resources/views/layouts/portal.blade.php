<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ __('ui.layouts.portal.meta_description') }}">
    <meta name="theme-color" content="#0b4f9e">
    <title>@yield('title', __('messages.app_name'))</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="@versioned('css/portal.css')">
    @stack('styles')
</head>
<body class="d-flex flex-column min-vh-100">
@php
    /* The section anchors are **absolute**, not bare `#about`.
     *
     * This layout dresses the home page, the login and registration screens and
     * every Self-Inspection screen — and the sections these link to exist on the
     * home page alone. A bare fragment therefore did nothing on four screens out of
     * five: the menu looked live and was inert (reported 2026-09-06).
     *
     * Prefixed with `route('home')`, so a link navigates home and *then* scrolls,
     * from wherever it was clicked. On the home page itself a browser treats the
     * same-document URL as an ordinary fragment jump, so nothing is lost there.
     *
     * Declared here and not beside the navigation: the utility bar carries three of
     * these links and renders first.
     */
    $home = route('home');
    $onHome = request()->routeIs('home');
@endphp

{{-- Assessed on every government portal, and cheap to get right. --}}
<a class="skip-link" href="#main">{{ __('ui.skip_to_main_content') }}</a>

<header class="sticky-header">
    {{-- Utility bar --}}
    <div class="gov-topbar">
        <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2 py-1">
            <div class="d-flex align-items-center gap-3">
                <span class="d-none d-md-inline">{{ __('ui.layouts.portal.government_web_service') }}</span>
                <a href="{{ $home }}#help-desk" class="d-inline d-md-none">{{ __('ui.layouts.portal.help_desk') }}</a>
            </div>
            <div class="d-flex align-items-center gap-3">
                <a href="{{ $home }}#help-desk" class="d-none d-md-inline">{{ __('ui.layouts.portal.help_desk') }}</a>
                <a href="{{ $home }}#contact" class="d-none d-md-inline">{{ __('ui.contact') }}</a>
                <a href="{{ $home }}#accessibility" class="d-none d-lg-inline">{{ __('ui.layouts.portal.accessibility') }}</a>

                <span class="d-flex align-items-center gap-1" id="accessibility">
                    <span class="d-none d-sm-inline me-1">{{ __('ui.layouts.portal.font_size') }}</span>
                    <button type="button" class="btn-font" data-font="sm" aria-label="{{ __('ui.layouts.portal.normal_font_size') }}">{{ __('ui.layouts.portal.a') }}</button>
                    <button type="button" class="btn-font" data-font="md" aria-label="{{ __('ui.layouts.portal.default_font_size') }}" aria-pressed="true">A</button>
                    <button type="button" class="btn-font" data-font="lg" aria-label="{{ __('ui.layouts.portal.large_font_size') }}">{{ __('ui.layouts.portal.a_2') }}</button>
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
                        'alt' => __('ui.layouts.portal.emblem_alt'),
                    ])
                </div>
                <div class="col text-center">
                    <div class="brand-title">{{ __('ui.layouts.portal.brand_title') }}</div>
                    <div class="brand-sub">{{ __('ui.layouts.portal.brand_subtitle') }}</div>
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
    <nav class="gov-nav navbar navbar-expand-lg py-0" aria-label="{{ __('ui.main_navigation') }}">
        <div class="container">
            <button class="navbar-toggler my-2" type="button" data-bs-toggle="collapse"
                    data-bs-target="#govNav" aria-controls="govNav" aria-expanded="false"
                    aria-label="{{ __('ui.toggle_navigation') }}">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="govNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link @if ($onHome) active @endif" href="{{ $home }}"
                           @if ($onHome) aria-current="page" @endif>{{ __('ui.layouts.portal.home') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ $home }}#about">{{ __('ui.layouts.portal.about_dpc') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ $home }}#process">{{ __('ui.process_flow') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ $home }}#roles">{{ __('ui.user_roles') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ $home }}#manual">{{ __('ui.user_manual') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ $home }}#faq">{{ __('ui.layouts.portal.faq') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ $home }}#contact">{{ __('ui.contact') }}</a></li>
                </ul>
                {{-- Offering "Department Login" and "New Registration" to somebody
                     who is already signed in is how a department ends up registering
                     twice (reported 2026-09-06). Signed in, the same two slots carry
                     the two things that account actually wants: their dashboard, and
                     a way out.

                     `route('dashboard')` for every role — it is the one authenticated
                     landing page, and it sends an Administrator on to the admin
                     dashboard itself rather than this layout having to know which
                     role goes where. --}}
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2 py-2 py-lg-0">
                    @auth
                        <li class="nav-item d-none d-lg-flex align-items-center">
                            <span class="text-white-50 small text-truncate" style="max-width:14rem">{{ auth()->user()->name }}</span>
                        </li>
                        <li class="nav-item">
                            <a class="btn btn-sm btn-accent w-100" href="{{ route('dashboard') }}">{{ __('ui.dashboard_link') }}</a>
                        </li>
                        <li class="nav-item">
                            <form method="POST" action="{{ route('logout') }}" class="m-0" data-no-busy>
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-light w-100">{{ __('ui.logout') }}</button>
                            </form>
                        </li>
                    @else
                        <li class="nav-item">
                            <a class="btn btn-sm btn-outline-light w-100" href="{{ route('login') }}">{{ __('ui.department_login') }}</a>
                        </li>
                    @endauth
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
                        <h2 class="mb-1">{{ __('ui.chhattisgarh_public_service_commission') }}</h2>
                        <div class="small">छत्तीसगढ़ लोक सेवा आयोग</div>
                    </div>
                </div>
                <p class="small mb-0">
{!! __('ui.layouts.portal.footer_address') !!}
                </p>
            </div>

            <div class="col-6 col-lg-2">
                <h2 class="mb-3">{{ __('ui.layouts.portal.portal') }}</h2>
                <ul class="list-unstyled small d-grid gap-2 mb-0">
                    <li><a href="{{ route('home') }}">{{ __('ui.layouts.portal.home') }}</a></li>
                    <li><a href="{{ $home }}#about">{{ __('ui.layouts.portal.about_dpc') }}</a></li>
                    <li><a href="{{ $home }}#process">{{ __('ui.process_flow') }}</a></li>
                    <li><a href="{{ $home }}#roles">{{ __('ui.user_roles') }}</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <h2 class="mb-3">{{ __('ui.layouts.portal.support') }}</h2>
                <ul class="list-unstyled small d-grid gap-2 mb-0">
                    <li><a href="{{ $home }}#manual">{{ __('ui.user_manual') }}</a></li>
                    <li><a href="{{ $home }}#faq">{{ __('ui.layouts.portal.faq') }}</a></li>
                    <li><a href="{{ $home }}#help-desk">{{ __('ui.layouts.portal.help_desk') }}</a></li>
                    <li><a href="{{ $home }}#accessibility">{{ __('ui.layouts.portal.accessibility') }}</a></li>
                </ul>
            </div>

            <div class="col-lg-4" id="help-desk">
                <h2 class="mb-3">{{ __('ui.layouts.portal.help_desk') }}</h2>
                <ul class="list-unstyled small d-grid gap-2 mb-3">
                    <li class="d-flex gap-2">@include('partials.icon', ['name' => 'phone', 'size' => 16])
                        <span>{{ __('ui.layouts.portal.office_hours_working_days') }}</span></li>
                    <li class="d-flex gap-2">@include('partials.icon', ['name' => 'mail', 'size' => 16])
                        <span>{{ __('ui.layouts.portal.through_your_department_s_nodal_officer') }}</span></li>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <span class="small">{{ __('ui.layouts.portal.designed_amp_developed_with') }}</span>
                    @include('partials.logo', [
                        'which' => 'csc', 'class' => 'bg-white rounded p-1',
                        'style' => 'height:34px',
                        'alt' => __('ui.layouts.portal.developer_alt'),
                    ])
                </div>
            </div>
        </div>
    </div>

    <div class="gov-footer-bottom mt-4 py-3">
        <div class="container d-flex flex-wrap justify-content-between gap-2">
            <div>&copy; {{ now()->year }} {{ __('ui.layouts.portal.copyright') }}</div>
            <div class="d-flex flex-wrap gap-3">
                <a href="{{ $home }}#privacy">{{ __('ui.layouts.portal.privacy_policy') }}</a>
                <a href="{{ $home }}#terms">{{ __('ui.layouts.portal.terms_of_use') }}</a>
                <a href="{{ $home }}#help-desk">{{ __('ui.layouts.portal.help_desk') }}</a>
                {!! __('ui.layouts.portal.version', ['number' => e(config('dpc.application.version', '1.0'))]) !!}
            </div>
        </div>
    </div>
</footer>

<script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="@versioned('js/portal.js')" defer></script>
{{-- Every dropdown is searchable (2026-10-26), the public pages included. --}}
<script src="@versioned('js/searchable-select.js')" defer></script>
<script src="@versioned('js/table-hscroll.js')" defer></script>
@stack('scripts')
</body>
</html>
