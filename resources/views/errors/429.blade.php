@extends('layouts.app')

@section('title', '429 — ' . __('messages.app_name'))

@section('content')
    <div class="error-page">
        <div class="container">
            <div class="row justify-content-center text-center">
                <div class="col-lg-7">
                    <div class="gov-icon lg solid mx-auto mb-3">
                        @include('partials.icon', ['name' => 'bell', 'size' => 28])
                    </div>
                    <div class="error-code">429</div>
                    <h1 class="h4 mb-2">Too many requests</h1>
                    <p class="text-body-secondary mb-4">The action was attempted too often in a short time. Wait a moment and try again.</p>
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
