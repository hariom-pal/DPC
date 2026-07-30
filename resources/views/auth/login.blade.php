@extends('layouts.app')

@section('title', 'Login — ' . __('messages.app_name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3 text-center">Login / लॉग इन</h1>

                    @include('partials.alerts')

                    <form method="POST" action="{{ route('login.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="login" class="form-label">Email or Mobile / ईमेल या मोबाइल</label>
                            <input type="text" class="form-control @error('login') is-invalid @enderror"
                                   id="login" name="login" value="{{ old('login') }}" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password / पासवर्ड</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="remember" id="remember">
                            <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Login</button>
                    </form>

                    <p class="text-center text-muted small mt-3 mb-0">
                        New department? <a href="{{ route('register') }}">Register here</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
