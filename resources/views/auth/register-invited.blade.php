@extends('layouts.portal')

@section('title', __('invitations.register.title'))

@section('content')
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <h1 class="h4 mb-1">{{ __('invitations.register.title') }}</h1>
                <p class="text-body-secondary small">{{ __('invitations.register.step1') }}</p>

                @include('partials.alerts')

                <div class="gov-card">
                    <div class="card-body p-4">
                        {{-- The department, read-only from the Department Master. --}}
                        <dl class="row small mb-4" id="invited-department">
                            <dt class="col-sm-4">{{ __('invitations.register.department') }}</dt>
                            <dd class="col-sm-8">{{ $invitation->department->name }} ({{ $invitation->department->code }})</dd>
                            <dt class="col-sm-4">{{ __('invitations.register.address') }}</dt>
                            <dd class="col-sm-8">{{ $invitation->department->district?->name_en }}, {{ $invitation->department->place }} — {{ $invitation->department->pin }}</dd>
                            <dt class="col-sm-4">{{ __('invitations.register.official_email') }}</dt>
                            <dd class="col-sm-8">{{ $invitation->email }}</dd>
                        </dl>

                        <form method="POST" action="{{ route('register.invitation.start', ['token' => $token]) }}">
                            @csrf
                            <div class="mb-3">
                                <label for="mobile" class="form-label">{{ __('ui.mobile') }}</label>
                                <input type="text" class="form-control @error('mobile') is-invalid @enderror" id="mobile" name="mobile" value="{{ old('mobile') }}" required maxlength="10" inputmode="numeric">
                                @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-primary">{{ __('invitations.register.send_otp') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
