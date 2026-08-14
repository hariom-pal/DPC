<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('messages.app_name'))</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    @stack('styles')
</head>
<body class="bg-body-tertiary d-flex flex-column min-vh-100">
    <nav class="navbar navbar-expand-lg navbar-dark shadow-sm" style="background-color:#0b3d2e;">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="{{ route('home') }}">
                {{ __('messages.app_name') }}
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                @auth
                    <span class="text-white small d-none d-md-inline">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}" class="m-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-light">Logout</button>
                    </form>
                @else
                    <a class="btn btn-sm btn-outline-light" href="{{ route('login') }}">Login</a>
                    <a class="btn btn-sm btn-light" href="{{ route('register') }}">Register</a>
                @endauth
                <span class="vr text-white-50 mx-1"></span>
                <a class="btn btn-sm {{ app()->getLocale() === 'en' ? 'btn-light' : 'btn-outline-light' }}"
                   href="{{ route('lang.switch', 'en') }}">EN</a>
                <a class="btn btn-sm {{ app()->getLocale() === 'hi' ? 'btn-light' : 'btn-outline-light' }}"
                   href="{{ route('lang.switch', 'hi') }}">हिंदी</a>
            </div>
        </div>
    </nav>

    <main class="container py-4 flex-grow-1">
        {{-- Architect Directive 3 (M8): "THIS IS A REVIEW DPC" throughout the
             Review workflow. Driven by a view composer rather than by editing
             each screen, because the M4 proposal views are frozen. --}}
        @includeWhen(($isReviewDpc ?? false), 'partials.review-banner')
        @yield('content')
    </main>

    <footer class="text-center text-muted small py-4">
        &copy; {{ now()->year }} {{ __('messages.welcome_subtitle') }}
    </footer>

    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    @stack('scripts')
</body>
</html>
