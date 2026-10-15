@extends('layouts.app')

@section('title', '503 — ' . __('messages.app_name'))

@section('content')
    <div class="error-page">
        <div class="container">
            <div class="row justify-content-center text-center">
                <div class="col-lg-7">
                    <div class="gov-icon lg solid mx-auto mb-3">
                        @include('partials.icon', ['name' => 'refresh', 'size' => 28])
                    </div>
                    <div class="error-code">503</div>
                    <h1 class="h4 mb-2">{{ __('errorpages.errors.503.under_maintenance') }}</h1>
                    <p class="text-body-secondary mb-4">{{ __('errorpages.errors.503.the_portal_is_briefly_unavailable_while_maintena') }}</p>
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <a href="{{ url('/') }}" class="btn btn-primary">{{ __('ui.go_to_the_home_page') }}</a>
                        @auth
                            <a href="{{ route('dashboard') }}" class="btn btn-outline-primary">{{ __('ui.dashboard_link') }}</a>
                        @endauth
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
