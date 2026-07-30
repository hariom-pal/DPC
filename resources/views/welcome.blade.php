@extends('layouts.app')

@section('title', __('messages.welcome_title'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4 p-md-5 text-center">
                    <h1 class="h3 mb-1">{{ __('messages.welcome_title') }}</h1>
                    <p class="text-muted mb-4">{{ __('messages.welcome_subtitle') }}</p>
                    <span class="badge text-bg-success px-3 py-2">{{ __('messages.foundation_ready') }}</span>
                </div>
            </div>
        </div>
    </div>
@endsection
