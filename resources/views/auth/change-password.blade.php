@extends('layouts.app')

@section('title', 'Change Password — ' . __('messages.app_name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3 text-center">Change Password / पासवर्ड बदलें</h1>
                    <p class="text-muted small">You are using a system-issued password. Please set a new password to continue.</p>

                    @include('partials.alerts')

                    <form method="POST" action="{{ route('password.change.update') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current (temporary) password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">New password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <div class="form-text">Min 8 chars with upper &amp; lower case, a number and a symbol.</div>
                        </div>
                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Confirm new password</label>
                            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
