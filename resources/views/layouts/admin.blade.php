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
    <link rel="stylesheet" href="{{ asset('css/portal.css') }}">
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
                <span>e-DPC Portal — Administration</span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <span class="text-white small d-none d-md-inline">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-light">Logout</button>
                </form>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <aside class="col-12 col-md-3 col-lg-2 bg-white border-end min-vh-100 p-0">
                <div class="list-group list-group-flush">
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"
                       href="{{ route('admin.dashboard') }}">Dashboard</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.districts.*') ? 'active' : '' }}"
                       href="{{ route('admin.districts.index') }}">Districts</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.designations.*') ? 'active' : '' }}"
                       href="{{ route('admin.designations.index') }}">Designations</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.pay-scales.*') ? 'active' : '' }}"
                       href="{{ route('admin.pay-scales.index') }}">Pay Scales</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.cadres.*') ? 'active' : '' }}"
                       href="{{ route('admin.cadres.index') }}">Cadres</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.reservation-policies.*') ? 'active' : '' }}"
                       href="{{ route('admin.reservation-policies.index') }}">Reservation Policies</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.reservation-rosters.*') ? 'active' : '' }}"
                       href="{{ route('admin.reservation-rosters.index') }}">Reservation Rosters</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.committee-types.*') ? 'active' : '' }}"
                       href="{{ route('admin.committee-types.index') }}">Committee Types</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.committee-configurations.*') ? 'active' : '' }}"
                       href="{{ route('admin.committee-configurations.index') }}">Committee Configurations</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.recruitment-rules.*') ? 'active' : '' }}"
                       href="{{ route('admin.recruitment-rules.index') }}">Recruitment Rules</a>
                    <a class="list-group-item list-group-item-action fw-semibold {{ request()->routeIs('admin.users.*') ? 'active' : '' }}"
                       href="{{ route('admin.users.index') }}">User Management</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.reservation-categories.*') ? 'active' : '' }}"
                       href="{{ route('admin.reservation-categories.index') }}">Reservation Categories</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.vacancy-types.*') ? 'active' : '' }}"
                       href="{{ route('admin.vacancy-types.index') }}">Vacancy Types</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.dpc-types.*') ? 'active' : '' }}"
                       href="{{ route('admin.dpc-types.index') }}">DPC Types</a>
                    <a class="list-group-item list-group-item-action {{ request()->routeIs('admin.review-reasons.*') ? 'active' : '' }}"
                       href="{{ route('admin.review-reasons.index') }}">Review Reasons</a>
                </div>
            </aside>
            <main class="col-12 col-md-9 col-lg-10 py-4">
                @include('partials.alerts')
                @yield('content')
            </main>
        </div>
    </div>

    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('js/portal.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
