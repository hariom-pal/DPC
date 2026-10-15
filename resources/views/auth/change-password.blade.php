@extends('layouts.app')

@section('title', 'Change Password — ' . __('messages.app_name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3 text-center">{{ __('authui.change_password.change_password') }}</h1>
                    <p class="text-muted small">{{ __('authui.change_password.you_are_using_a_system_issued_password_please_se') }}</p>

                    @include('partials.alerts')

                    <form method="POST" action="{{ route('password.change.update') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="current_password" class="form-label">{{ __('authui.change_password.current_temporary_password') }}</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">{{ __('authui.change_password.new_password') }}</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <div class="form-text">{!! __('authui.change_password.hint_password') !!}</div>
                        </div>
                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">{{ __('authui.change_password.confirm_new_password') }}</label>
                            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">{{ __('authui.change_password.update_password') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
