@extends('layouts.portal')

@section('title', __('invitations.register.title'))

@section('content')
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <h1 class="h4 mb-1">{{ __('invitations.register.title') }}</h1>
                <p class="text-body-secondary small">{{ __('invitations.register.step3') }}</p>

                @include('partials.alerts')

                <div class="gov-card">
                    <div class="card-body p-4" id="register-identity">
                        <p class="small">{{ __('invitations.register.digilocker_lead') }}</p>
                        @error('identity')<div class="alert alert-danger small">{{ $message }}</div>@enderror
                        <form method="POST" action="{{ route('register.invitation.identity.start', ['token' => $token]) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">{{ __('identity.start') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
