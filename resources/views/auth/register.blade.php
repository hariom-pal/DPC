@extends('layouts.portal')

@section('title', __('invitations.register.title'))

@section('content')
    <div class="py-5" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
        <div class="container py-lg-3">
            <div class="row justify-content-center">
                <div class="col-lg-7">
                    <div class="text-center mb-4">
                        @include('partials.logo', ['which' => 'cgpsc', 'class' => 'mb-3', 'style' => 'height:56px', 'alt' => ''])
                        <h1 class="h4 mb-1">{{ __('invitations.register.title') }}</h1>
                    </div>
                    <div class="gov-card">
                        <div class="card-body p-4">
                            <p class="mb-2">{{ __('invitations.register.by_invitation') }}</p>
                            <p class="small text-body-secondary mb-0">{{ __('invitations.register.how') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
