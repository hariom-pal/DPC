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
<a class="skip-link" href="#main">{{ __('ui.skip_to_main_content') }}</a>

<header class="sticky-header">
    <nav class="app-shell-header navbar navbar-expand-lg navbar-dark shadow-sm" aria-label="{{ __('ui.main_navigation') }}">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('home') }}">
                @include('partials.logo', [
                    'which' => 'cgpsc', 'class' => 'bg-white rounded-circle p-1',
                    'style' => 'height:34px', 'alt' => '',
                ])
                <span class="d-flex flex-column lh-sm">
                    <span class="fw-semibold">{{ __('ui.layouts.app.e_dpc_portal') }}</span>
                    <span class="small fw-normal" style="font-size:.72rem;opacity:.85">
                        {{ __('ui.chhattisgarh_public_service_commission') }}
                    </span>
                </span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#appNav" aria-controls="appNav" aria-expanded="false"
                    aria-label="{{ __('ui.toggle_navigation') }}">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="appNav">
                <div class="ms-auto d-flex flex-wrap align-items-lg-center gap-2 py-2 py-lg-0">
                    @auth
                        <span class="nav-user d-none d-md-inline me-1">
                            {{ auth()->user()->name }}
                        </span>
                        {{-- M11. Reaches every authenticated screen from here,
                             including the frozen ones. --}}
                        @include('partials.notification-bell')
                        <a class="btn btn-sm btn-outline-light" href="{{ route('dashboard') }}">{{ __('ui.dashboard_link') }}</a>
                        {{-- Change item 17: the candidate's own DPC, and the only
                             departmental screen their role reaches. --}}
                        @role(\App\Enums\Role::PromotionCandidate->value)
                            <a class="btn btn-sm btn-outline-light" href="{{ route('my-dpc.index') }}">{{ __('ui.my_promotion') }}</a>
                        @endrole
                        {{-- M11. Every role has a reports screen; it scopes itself. --}}
                        <a class="btn btn-sm btn-outline-light d-none d-lg-inline" href="{{ route('reports.index') }}">{{ __('ui.reports') }}</a>

                        {{-- Item 9 (2026-08-26): one account, several roles, and the
                             one it is acting as gates the session.

                             Shown only when there is a choice to make. An account
                             with one role has nothing to switch to, and a control
                             offering its own single option reads as a fault. --}}
                        @php
                            $actingAs = auth()->user()->activeRole();
                            $mayActAs = auth()->user()->grantedRoles();
                        @endphp
                        @if (count($mayActAs) > 1)
                            <div class="dropdown m-0">
                                <button class="btn btn-sm btn-accent dropdown-toggle" type="button"
                                        data-bs-toggle="dropdown" aria-expanded="false">
                                    {{ __('ui.acting_as', ['role' => $actingAs?->label() ?? '—']) }}
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    @foreach ($mayActAs as $option)
                                        <li>
                                            <form method="POST" action="{{ route('acting-as') }}" class="m-0" data-no-busy>
                                                @csrf
                                                <input type="hidden" name="role" value="{{ $option->value }}">
                                                <button type="submit"
                                                        class="dropdown-item {{ $option === $actingAs ? 'active' : '' }}"
                                                        @disabled($option === $actingAs)>
                                                    {{ $option->label() }}
                                                </button>
                                            </form>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('logout') }}" class="m-0" data-no-busy>
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-light">{{ __('ui.logout') }}</button>
                        </form>
                    @else
                        <a class="btn btn-sm btn-outline-light" href="{{ route('login') }}">{{ __('ui.login') }}</a>
                        <a class="btn btn-sm btn-accent" href="{{ route('register') }}">{{ __('ui.layouts.app.register') }}</a>
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
        {!! __('ui.layouts.app.copyright', ['year' => now()->year]) !!}
        {!! __('ui.layouts.app.version', ['number' => e(config('dpc.application.version', '1.0'))]) !!}
    </div>
</footer>

<script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/portal.js') }}" defer></script>
{{-- Searchable dropdowns. Loaded on the department shell as well as the admin
     one from 2026-08-18, when the Pre-DPC screen started selecting posts,
     feeder posts and qualifications from the masters. It is a progressive
     enhancement over a native <select>, so a screen that does not use one is
     unaffected by its presence. --}}
<script src="{{ asset('js/searchable-select.js') }}" defer></script>
<script src="{{ asset('js/multiselect-dropdown.js') }}" defer></script>
<script src="{{ asset('js/table-hscroll.js') }}" defer></script>
@stack('scripts')
</body>
</html>
