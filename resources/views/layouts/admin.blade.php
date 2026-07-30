<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — {{ __('messages.app_name') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    @stack('styles')
</head>
<body class="bg-body-tertiary">
    <nav class="navbar navbar-dark shadow-sm" style="background-color:#0b3d2e;">
        <div class="container-fluid">
            <a class="navbar-brand fw-semibold" href="{{ route('admin.dashboard') }}">
                {{ __('messages.app_name') }} — Admin
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
    @stack('scripts')
</body>
</html>
