<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — {{ __('messages.app_name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <meta name="theme-color" content="#0b4f9e">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="@versioned('css/portal.css')">
    @stack('styles')
</head>
<body>
    <nav class="app-shell-header navbar navbar-dark shadow-sm sticky-header">
        <div class="container-fluid">
            <a class="navbar-brand fw-semibold d-flex align-items-center gap-2" href="{{ route('admin.dashboard') }}">
                @include('partials.logo', [
                    'which' => 'cgpsc', 'class' => 'bg-white rounded-circle p-1',
                    'style' => 'height:32px', 'alt' => '',
                ])
                <span>{{ __('ui.layouts.admin.e_dpc_portal_administration') }}</span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <span class="text-white small d-none d-md-inline">{{ auth()->user()->name }}</span>
                {{-- M11. The administration shell is a separate layout, so the
                     bell is included here too and the composer is registered for
                     both — a notification an Admin never sees is one that was not
                     delivered. --}}
                @include('partials.notification-bell')
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-light">{{ __('ui.logout') }}</button>
                </form>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <aside class="col-12 col-md-3 col-lg-2 bg-white border-end min-vh-100 p-0">
                <div class="list-group list-group-flush">
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"
                       href="{{ route('admin.dashboard') }}">{{ __('ui.layouts.admin.nav.dashboard') }}</a>
                    <a class="list-group-item list-group-item-action fw-semibold {{ request()->routeIs('admin.departments.*') ? 'active' : '' }}"
                       href="{{ route('admin.departments.index') }}">{{ __('ui.layouts.admin.nav.departments') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.department-invitations.*') ? 'active' : '' }}"
                       href="{{ route('admin.department-invitations.index') }}">{{ __('invitations.admin.title') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.districts.*') ? 'active' : '' }}"
                       href="{{ route('admin.districts.index') }}">{{ __('ui.layouts.admin.nav.districts') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.designations.*') ? 'active' : '' }}"
                       href="{{ route('admin.designations.index') }}">{{ __('ui.layouts.admin.nav.designations') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.pay-scales.*') ? 'active' : '' }}"
                       href="{{ route('admin.pay-scales.index') }}">{{ __('ui.layouts.admin.nav.pay_scales') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.department-posts.*') ? 'active' : '' }}"
                       href="{{ route('admin.department-posts.index') }}">{{ __('ui.layouts.admin.nav.department_posts') }}</a>

                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.vacancy-management.*', 'admin.departments.vacancy-master.*') ? 'active' : '' }}"
                       href="{{ route('admin.vacancy-management.index') }}">{{ __('vacancymaster.title') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.service-classes.*') ? 'active' : '' }}"
                       href="{{ route('admin.service-classes.index') }}">{{ __('ui.layouts.admin.nav.class_master') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.qualifications.*') ? 'active' : '' }}"
                       href="{{ route('admin.qualifications.index') }}">{{ __('ui.layouts.admin.nav.qualifications') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.reservation-policies.*') ? 'active' : '' }}"
                       href="{{ route('admin.reservation-policies.index') }}">{{ __('ui.layouts.admin.nav.reservation_policies') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.reservation-rosters.*') ? 'active' : '' }}"
                       href="{{ route('admin.reservation-rosters.index') }}">{{ __('ui.layouts.admin.nav.reservation_rosters') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.cgpsc-chairman.*') ? 'active' : '' }}"
                       href="{{ route('admin.cgpsc-chairman.index') }}">{{ __('ui.layouts.admin.nav.cgpsc_chairman') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.committee-configurations.*') ? 'active' : '' }}"
                       href="{{ route('admin.committee-configurations.index') }}">{{ __('ui.layouts.admin.nav.committee_configurations') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.cr-grades.*') ? 'active' : '' }}"
                       href="{{ route('admin.cr-grades.index') }}">{{ __('admin.cr_grades.title') }}</a>
                    <a class="list-group-item list-group-item-action fw-semibold {{ request()->routeIs('admin.users.*') ? 'active' : '' }}"
                       href="{{ route('admin.users.index') }}">{{ __('ui.layouts.admin.nav.user_management') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.assignment-requests.*') ? 'active' : '' }}"
                       href="{{ route('admin.assignment-requests.index') }}">{{ __('assignments.admin_title') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.reservation-categories.*') ? 'active' : '' }}"
                       href="{{ route('admin.reservation-categories.index') }}">{{ __('ui.layouts.admin.nav.reservation_categories') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.dpc-types.*') ? 'active' : '' }}"
                       href="{{ route('admin.dpc-types.index') }}">{{ __('ui.layouts.admin.nav.dpc_types') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.review-reasons.*') ? 'active' : '' }}"
                       href="{{ route('admin.review-reasons.index') }}">{{ __('ui.layouts.admin.nav.review_reasons') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.record-reasons.*') ? 'active' : '' }}"
                       href="{{ route('admin.record-reasons.index') }}">{{ __('ui.layouts.admin.nav.record_reasons') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.sealed-cover-reasons.*') ? 'active' : '' }}"
                       href="{{ route('admin.sealed-cover-reasons.index') }}">{{ __('ui.layouts.admin.nav.sealed_cover_reasons') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('previous-dpcs.*') ? 'active' : '' }}"
                       href="{{ route('previous-dpcs.index') }}">{{ __('previous_dpc.title') }}</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.portal-notices.*') ? 'active' : '' }}"
                       href="{{ route('admin.portal-notices.index') }}">{{ __('ui.layouts.admin.nav.portal_notices') }}</a>
                    <a class="list-group-item list-group-item-action fw-semibold {{ request()->routeIs('admin.pre-dpc-links.*') ? 'active' : '' }}"
                       href="{{ route('admin.pre-dpc-links.index') }}">{{ __('ui.layouts.admin.nav.pre_dpc_links') }}</a>
                </div>
            </aside>
            <main class="col-12 col-md-9 col-lg-10 py-4">
                @include('partials.alerts')
                @yield('content')
            </main>
        </div>
    </div>

    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="@versioned('js/portal.js')" defer></script>
    {{-- Makes every single-choice <select> a type-to-filter dropdown (2026-10-26).
         Deferred and additive: without it the selects stay plain and usable. --}}
    <script src="@versioned('js/searchable-select.js')" defer></script>
    <script src="@versioned('js/multiselect-dropdown.js')" defer></script>
    <script src="@versioned('js/table-hscroll.js')" defer></script>
    @stack('scripts')
</body>
</html>
