@extends('layouts.portal')

@section('title', __('invitations.register.title'))

@section('content')
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <h1 class="h4 mb-1">{{ __('invitations.register.title') }}</h1>
                <p class="text-body-secondary small">{{ __('invitations.register.step4') }}</p>

                @include('partials.alerts')

                <div class="gov-card">
                    <div class="card-body p-4">
                        {{-- From DigiLocker: read-only, and the account's identity. --}}
                        <dl class="row small" id="digilocker-identity">
                            <dt class="col-sm-5">{{ __('invitations.register.full_name') }}</dt><dd class="col-sm-7">{{ $identity['name'] }}</dd>
                            <dt class="col-sm-5">{{ __('identity.field.date_of_birth') }}</dt><dd class="col-sm-7">{{ \Illuminate\Support\Carbon::parse($identity['date_of_birth'])->format('d-m-Y') }}</dd>
                            <dt class="col-sm-5">{{ __('identity.field.gender') }}</dt><dd class="col-sm-7">{{ __('identity.gender.'.$identity['gender']) }}</dd>
                        </dl>
                        <p class="small text-body-secondary">{{ __('invitations.register.from_digilocker') }}</p>
                        <form method="POST" action="{{ route('register.invitation.complete', ['token' => $token]) }}">
                            @csrf
                            <div class="mb-3">
                                <label for="password" class="form-label">{{ __('invitations.register.password') }}</label>
                                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required autocomplete="new-password">
                                <div class="form-text">{{ __('invitations.register.password_rules') }}</div>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label for="password_confirmation" class="form-label">{{ __('invitations.register.password_confirm') }}</label>
                                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
                            </div>
                            <button type="submit" class="btn btn-success w-100">{{ __('invitations.register.complete') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
