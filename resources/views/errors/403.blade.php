@extends('layouts.app')

@section('title', '403 — ' . __('messages.app_name'))

@section('content')
    <div class="error-page">
        <div class="container">
            <div class="row justify-content-center text-center">
                <div class="col-lg-7">
                    <div class="gov-icon lg solid mx-auto mb-3">
                        @include('partials.icon', ['name' => 'lock', 'size' => 28])
                    </div>
                    <div class="error-code">403</div>
                    <h1 class="h4 mb-2">Access denied</h1>
                    <p class="text-body-secondary mb-4">You are signed in, but this action is not permitted for your role or your department. If you believe it should be, contact your nodal officer.</p>
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <a href="{{ url('/') }}" class="btn btn-primary">Go to the home page</a>
                        @auth
                            <a href="{{ route('dashboard') }}" class="btn btn-outline-primary">Dashboard</a>
                        @endauth
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
