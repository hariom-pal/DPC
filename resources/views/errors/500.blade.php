@extends('layouts.app')

@section('title', '500 — ' . __('messages.app_name'))

@section('content')
    <div class="error-page">
        <div class="container">
            <div class="row justify-content-center text-center">
                <div class="col-lg-7">
                    <div class="gov-icon lg solid mx-auto mb-3">
                        @include('partials.icon', ['name' => 'shield', 'size' => 28])
                    </div>
                    <div class="error-code">500</div>
                    <h1 class="h4 mb-2">{{ __('errorpages.errors.500.something_went_wrong') }}</h1>
                    <p class="text-body-secondary mb-4">{{ __('errorpages.errors.500.the_problem_has_been_recorded_nothing_you_submit') }}</p>
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
