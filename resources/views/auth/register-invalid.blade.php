@extends('layouts.portal')

@section('title', __('invitations.register.title'))

@section('content')
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="alert alert-warning" id="invitation-invalid">
                    <strong class="d-block mb-1">{{ __('invitations.register.invalid_heading') }}</strong>
                    {{ __('invitations.register.invalid_body') }}
                </div>
            </div>
        </div>
    </div>
@endsection
